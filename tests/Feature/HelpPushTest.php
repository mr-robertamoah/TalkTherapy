<?php

use App\Actions\Help\SweepHelpRequestsAction;
use App\Actions\User\AnonymiseClosedAccountAction;
use App\Contracts\PushSender;
use App\Events\HelpRequestRingingEvent;
use App\Events\HelpRequestUpdatedEvent;
use App\Jobs\SendHelpPushJob;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\HelpRequest;
use App\Models\PushSubscription;
use App\Models\Report;
use App\Models\Suspension;
use App\Models\User;
use App\Support\MinishlinkPushSender;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

// SCRUM-399: web push for urgent help rings -- devices, who is pushed, what the push carries, withdrawal, and
// cleanup. A fake sender stands in for the push services. Helpers are file-local and hpu-prefixed (--parallel).

class HpuFakeSender implements PushSender
{
    /** @var list<array{ids: list<int>, payload: array, options: array}> */
    public array $sent = [];

    /** @var array<int, string> */
    public array $answers = [];

    public function send(iterable $subscriptions, string $payload, array $options): array
    {
        $ids = collect($subscriptions)->pluck('id')->all();
        $this->sent[] = ['ids' => $ids, 'payload' => json_decode($payload, true), 'options' => $options];

        return collect($ids)->mapWithKeys(fn (int $id) => [$id => $this->answers[$id] ?? PushSender::SENT])->all();
    }
}

// A usable browser key pair, as a browser sends them (base64url): a 65-byte uncompressed point and a 16-byte secret.
function hpuKeys(): array
{
    $b64 = fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

    return ['p256dh' => $b64("\x04".random_bytes(64)), 'auth' => $b64(random_bytes(16))];
}

function hpuFake(): HpuFakeSender
{
    $fake = new HpuFakeSender;
    app()->instance(PushSender::class, $fake);

    return $fake;
}

function hpuOnCall(array $state = []): Counsellor
{
    return Counsellor::factory()->for(User::factory()->adult()->state(['created_at' => now()->subDays(30), 'email_verified_at' => now()]))
        ->create(['urgent_help_available_until' => now()->addHours(2), ...$state]);
}

function hpuDevice(User $user, ?string $endpoint = null): PushSubscription
{
    $endpoint ??= 'https://push.example/'.Str::random(20);

    return PushSubscription::query()->create([
        'user_id' => $user->id, 'endpoint' => $endpoint, 'endpoint_hash' => PushSubscription::hashEndpoint($endpoint),
        'public_key' => 'BPublicKeyExample', 'auth_token' => 'authExample', 'content_encoding' => 'aes128gcm', 'label' => 'Chrome on Android',
    ]);
}

function hpuAsk($test, ?User $person = null): HelpRequest
{
    $person ??= User::factory()->adult()->create(['created_at' => now()->subDays(30), 'email_verified_at' => now(), 'firstName' => 'Secretfirst']);
    $id = $test->actingAs($person)->postJson(route('help.requests.store'), ['note' => 'private words'])->assertOk()->json('helpRequest.id');

    return HelpRequest::query()->find($id);
}

beforeEach(function () {
    Event::fake([HelpRequestRingingEvent::class, HelpRequestUpdatedEvent::class]);
    config([
        'help.push.enabled' => true, 'help.push.vapid_public_key' => 'BPublicExample', 'help.push.vapid_private_key' => 'privateExample',
        'help.push.ttl' => 120, 'help.new_account_requests_per_hour' => 3, 'help.rings_per_minute' => 30,
        'help.push.endpoint_hosts' => ['push.example', '*.push.services.mozilla.com'],
    ]);
    RateLimiter::clear('help-rings:established');
});

test('a ring is pushed to the devices of counsellors it rings -- ids only, short-lived and urgent', function () {
    $fake = hpuFake();
    $onCall = hpuOnCall();
    $offDuty = Counsellor::factory()->for(User::factory()->adult())->create();
    $device = hpuDevice($onCall->user);
    hpuDevice($offDuty->user);

    $help = hpuAsk($this);

    expect($fake->sent)->toHaveCount(1);
    $push = $fake->sent[0];
    expect($push['ids'])->toBe([$device->id])
        ->and($push['payload'])->toBe(['type' => 'ring', 'tag' => SendHelpPushJob::reference($help->id)])
        ->and($push['options'])->toBe(['TTL' => 120, 'urgency' => 'high', 'topic' => SendHelpPushJob::reference($help->id)]);
    // The push services see an opaque reference, never the request's id.
    expect(SendHelpPushJob::reference($help->id))->toMatch('/^h[A-Za-z0-9_-]{24}$/')->not->toBe(SendHelpPushJob::reference($help->id + 1));
    expect(json_encode($push))->not->toContain('private words')->not->toContain('Secretfirst');
    expect($device->fresh()->last_used_at)->not->toBeNull();
});

test('answering it withdraws the push from everyone who was rung', function () {
    $fake = hpuFake();
    [$first, $second] = [hpuOnCall(), hpuOnCall()];
    [$a, $b] = [hpuDevice($first->user), hpuDevice($second->user)];
    $help = hpuAsk($this);

    $this->actingAs($first->user)->postJson(route('help.requests.accept', ['helpRequest' => $help->id]))->assertOk();

    $withdraw = collect($fake->sent)->firstWhere('payload.type', 'withdraw');
    expect($withdraw)->not->toBeNull()
        ->and(collect($withdraw['ids'])->sort()->values()->all())->toBe(collect([$a->id, $b->id])->sort()->values()->all());
});

test('an administrator ringing a counsellor directly pushes to them, even off call', function () {
    $fake = hpuFake();
    $help = hpuAsk($this);
    $direct = Counsellor::factory()->for(User::factory()->adult())->create();
    $device = hpuDevice($direct->user);
    $admin = User::factory()->adult()->has(Administrator::factory())->create();

    $this->actingAs($admin)->postJson(route('admin.help_requests.ring_counsellor', ['helpRequest' => $help->id]), ['counsellorId' => $direct->id])->assertOk();

    expect(collect($fake->sent)->contains(fn ($push) => $push['payload']['type'] === 'ring' && $push['ids'] === [$device->id]))->toBeTrue();
});

test('no push when the ring cap skips the ring, when push is off, or without keys', function () {
    $fake = hpuFake();
    hpuDevice(hpuOnCall()->user);

    config(['help.rings_per_minute' => 1]);
    hpuAsk($this);
    hpuAsk($this);
    expect($fake->sent)->toHaveCount(1);

    config(['help.push.enabled' => false]);
    hpuAsk($this);
    config(['help.push.enabled' => true, 'help.push.vapid_private_key' => null, 'help.rings_per_minute' => 30]);
    RateLimiter::clear('help-rings:established');
    hpuAsk($this);
    expect($fake->sent)->toHaveCount(1);
});

test('a device the push service no longer knows is deleted', function () {
    $fake = hpuFake();
    $device = hpuDevice(hpuOnCall()->user);
    $fake->answers[$device->id] = PushSender::GONE;

    hpuAsk($this);

    expect(PushSubscription::query()->find($device->id))->toBeNull();
});

test('a counsellor manages their own devices; nobody else\'s, and the keys never come back', function () {
    $counsellor = hpuOnCall();
    $other = hpuOnCall();
    $theirs = hpuDevice($other->user);

    $response = $this->actingAs($counsellor->user)->postJson(route('push.subscriptions.store'), [
        'endpoint' => 'https://push.example/mine', 'keys' => $keys = hpuKeys(), 'label' => 'Firefox on Linux',
    ])->assertOk()->assertJsonPath('enabled', true)->assertJsonPath('publicKey', 'BPublicExample');
    expect($response->json('devices'))->toHaveCount(1)
        ->and($response->json('devices.0'))->toMatchArray(['label' => 'Firefox on Linux', 'current' => true])
        ->and($response->getContent())->not->toContain('push.example')->not->toContain($keys['p256dh']);
    expect(PushSubscription::query()->where('user_id', $counsellor->user_id)->first()->getRawOriginal('endpoint'))->not->toContain('push.example');

    $this->actingAs($counsellor->user)->deleteJson(route('push.subscriptions.destroy', ['device' => $theirs->id]))->assertOk();
    expect($theirs->fresh())->not->toBeNull();

    // Not for someone who isn't a counsellor.
    $this->actingAs(User::factory()->adult()->create())->postJson(route('push.subscriptions.store'), [
        'endpoint' => 'https://push.example/x', 'keys' => hpuKeys(),
    ])->assertStatus(403);

    // At most so many devices -- the oldest go.
    config(['help.push.devices_per_user' => 2]);
    foreach (['a', 'b', 'c'] as $suffix) {
        $this->actingAs($counsellor->user)->postJson(route('push.subscriptions.store'), ['endpoint' => "https://push.example/{$suffix}", 'keys' => hpuKeys()])->assertOk();
    }
    expect(PushSubscription::query()->where('user_id', $counsellor->user_id)->count())->toBe(2);
});

test('signing out and closing the account remove devices', function () {
    $counsellor = hpuOnCall();
    // The device turned on in this session, and one from another session.
    $thisSession = hpuDevice($counsellor->user);
    $thisSession->forceFill(['session_hash' => hash('sha256', session()->getId())])->save();
    $kept = hpuDevice($counsellor->user);

    event(new Logout('web', $counsellor->user));
    expect(PushSubscription::query()->where('user_id', $counsellor->user_id)->pluck('id')->all())->toBe([$kept->id]);

    // Suspension: HelpReportTest (suspending over a help report removes the person's devices too).
    $closed = User::factory()->adult()->create();
    hpuDevice($closed);
    $closed->delete();
    $closed->forceFill(['deleted_at' => now()->subDays(config('user.closed_account_retention_days') + 1)])->saveQuietly();
    AnonymiseClosedAccountAction::new()->execute($closed->fresh());
    expect(PushSubscription::query()->where('user_id', $closed->id)->count())->toBe(0);
});

test('a device must name a push service over https -- never an internal or plain-http address', function () {
    $counsellor = hpuOnCall();
    foreach (['http://push.example/x', 'https://db:3306/x', 'http://169.254.169.254/latest', 'https://push.example.evil.test/x', 'https://user@push.example/x', 'https://push.example:8443/x'] as $endpoint) {
        $this->actingAs($counsellor->user)->postJson(route('push.subscriptions.store'), ['endpoint' => $endpoint, 'keys' => hpuKeys()])
            ->assertStatus(422)->assertJsonValidationErrors('endpoint');
    }
    $this->actingAs($counsellor->user)->postJson(route('push.subscriptions.store'), ['endpoint' => 'https://updates.push.services.mozilla.com/wpush/v2/x', 'keys' => hpuKeys()])->assertOk();

    // Keys a push can't be encrypted with are refused too.
    foreach ([['p256dh' => 'BKey', 'auth' => hpuKeys()['auth']], ['p256dh' => hpuKeys()['p256dh'], 'auth' => 'short']] as $keys) {
        $this->actingAs($counsellor->user)->postJson(route('push.subscriptions.store'), ['endpoint' => 'https://push.example/k', 'keys' => $keys])->assertStatus(422);
    }

    // One saved before the rule (or once the list changes) is never posted to, and is removed.
    $old = hpuDevice($counsellor->user, 'http://mailpit:8025/x');
    $fine = hpuDevice($counsellor->user);
    $sent = app(MinishlinkPushSender::class)->send([$old], '{}', []);
    expect($sent)->toBe([$old->id => PushSender::GONE]);
    hpuFake();
    hpuAsk($this);
    expect(PushSubscription::query()->find($fine->id))->not->toBeNull();
});

test('this browser is known by its endpoint, not its session -- and removing it on sign-out works for a suspended account', function () {
    $counsellor = hpuOnCall();
    $mine = hpuDevice($counsellor->user, 'https://push.example/this-browser');
    $other = hpuDevice($counsellor->user);

    $devices = $this->actingAs($counsellor->user)->getJson(route('push.subscriptions.index', ['device' => hash('sha256', 'https://push.example/this-browser')]))->json('devices');
    expect(collect($devices)->firstWhere('id', $mine->id)['current'])->toBeTrue()
        ->and(collect($devices)->firstWhere('id', $other->id)['current'])->toBeFalse();

    $report = new Report(['description' => 'x']);
    $report->addedby()->associate(User::factory()->adult()->create());
    $report->reportable()->associate(GroupTherapy::factory()->create());
    $report->save();
    Suspension::query()->forceCreate(['user_id' => $counsellor->user_id, 'report_id' => $report->id, 'scope' => 'account', 'public_reason' => 'x', 'starts_at' => now()->subMinute(), 'ends_at' => now()->addDay()]);
    $this->actingAs($counsellor->user->fresh())->postJson(route('push.subscriptions.destroy_current'), ['endpoint' => 'https://push.example/this-browser'])->assertOk();
    expect(PushSubscription::query()->where('user_id', $counsellor->user_id)->pluck('id')->all())->toBe([$other->id]);
});

test('the withdrawal reaches a counsellor who went off call after being rung, on cancel, expiry and admin close', function () {
    foreach (['cancel', 'expire', 'admin'] as $how) {
        $fake = hpuFake();
        $rung = hpuOnCall();
        $device = hpuDevice($rung->user);
        $help = hpuAsk($this, $person = User::factory()->adult()->create(['created_at' => now()->subDays(30), 'email_verified_at' => now()]));
        expect($fake->sent[0]['ids'])->toBe([$device->id]);

        // Off call before it closes: no longer in ringingFor, but it was pushed -- the ledger remembers.
        $rung->forceFill(['urgent_help_available_until' => null])->save();
        match ($how) {
            'cancel' => $this->actingAs($person)->postJson(route('help.requests.cancel', ['helpRequest' => $help->id]))->assertOk(),
            'expire' => [$help->forceFill(['created_at' => now()->subHours(3)])->save(), SweepHelpRequestsAction::new()->execute()],
            'admin' => $this->actingAs(User::factory()->adult()->has(Administrator::factory())->create())
                ->postJson(route('admin.help_requests.close', ['helpRequest' => $help->id]), ['note' => 'x'])->assertOk(),
        };

        $withdraw = collect($fake->sent)->firstWhere('payload.type', 'withdraw');
        expect($withdraw['ids'] ?? null)->toBe([$device->id], "withdrawn on {$how}");
    }
});

test('a ring sent after the request was answered goes to nobody; re-ringing someone else keeps an on-call counsellor\'s alert', function () {
    $fake = hpuFake();
    $onCall = hpuOnCall();
    $onCallDevice = hpuDevice($onCall->user);
    $help = hpuAsk($this);
    $fake->sent = [];

    // An administrator rings someone directly, then someone else: the first direct one is withdrawn, but the
    // on-call counsellor (still rung) is never withdrawn while it waits.
    $admin = User::factory()->adult()->has(Administrator::factory())->create();
    [$first, $second] = [Counsellor::factory()->for(User::factory()->adult())->create(), Counsellor::factory()->for(User::factory()->adult())->create()];
    $firstDevice = hpuDevice($first->user);
    hpuDevice($second->user);
    $this->actingAs($admin)->postJson(route('admin.help_requests.ring_counsellor', ['helpRequest' => $help->id]), ['counsellorId' => $first->id])->assertOk();
    $this->actingAs($admin)->postJson(route('admin.help_requests.ring_counsellor', ['helpRequest' => $help->id]), ['counsellorId' => $second->id])->assertOk();
    $withdrawn = collect($fake->sent)->where('payload.type', 'withdraw')->pluck('ids')->flatten()->all();
    expect($withdrawn)->toContain($firstDevice->id)->not->toContain($onCallDevice->id);

    // Accepted, then a late ring job runs: nobody is pushed.
    $this->actingAs($onCall->user)->postJson(route('help.requests.accept', ['helpRequest' => $help->id]))->assertOk();
    $fake->sent = [];
    (new SendHelpPushJob(SendHelpPushJob::RING, $help->id, [$onCall->id]))->handle($fake);
    expect($fake->sent)->toBe([]);
});
