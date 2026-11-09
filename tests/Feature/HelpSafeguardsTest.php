<?php

use App\Actions\Help\SweepHelpRequestsAction;
use App\Enums\HelpRequestStatusEnum;
use App\Events\HelpRequestRingingEvent;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\HelpRequest;
use App\Models\Report;
use App\Models\Suspension;
use App\Models\User;
use App\Notifications\HelpRequestWaitingNotification;
use App\Support\SchedulerHeartbeat;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

// SCRUM-395: the urgent help safeguards -- a kill switch, a stricter limit for new or unverified accounts,
// a platform-wide ring cap in two buckets, an unanswered request not counting, and the health check
// noticing requests left waiting. Helpers are file-local and hsg-prefixed (--parallel).

function hsgOnCall(): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id, 'urgent_help_available_until' => now()->addHours(2)]);
}

function hsgEstablished(array $state = []): User
{
    return User::factory()->adult()->create(['created_at' => now()->subDays(30), 'email_verified_at' => now()->subDays(30), ...$state]);
}

// Asks, and (if it was made) closes it as the person cancelling would, so they can ask again. Returns the status.
function hsgAsk($test, User $person): int
{
    $response = $test->actingAs($person)->postJson(route('help.requests.store'));
    if ($response->status() === 200) {
        HelpRequest::query()->whereKey($response->json('helpRequest.id'))->update(['status' => HelpRequestStatusEnum::cancelled->value, 'active_user_id' => null]);
    }

    return $response->status();
}

beforeEach(function () {
    RateLimiter::clear('help-rings:established');
    RateLimiter::clear('help-rings:new');
    config(['help.requests_per_hour' => 3, 'help.new_account_requests_per_hour' => 1, 'help.new_account_hours' => 24, 'help.rings_per_minute' => 30]);
});

test('with the kill switch off nobody can start a request, but a live one still comes back', function () {
    $waiting = hsgEstablished();
    $existing = $this->actingAs($waiting)->postJson(route('help.requests.store'))->assertOk()->json('helpRequest.id');

    config(['help.enabled' => false]);

    $refused = hsgEstablished();
    $this->actingAs($refused)->postJson(route('help.requests.store'))
        ->assertStatus(503)->assertJsonPath('code', 'help_unavailable')
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'emergency number'));
    expect(HelpRequest::query()->count())->toBe(1);

    expect($this->actingAs($waiting)->postJson(route('help.requests.store'))->assertOk()->json('helpRequest.id'))->toBe($existing);
    $this->actingAs($refused)->getJson(route('help.emergency_numbers'))->assertOk();

    // A refusal while it's off doesn't use up their allowance.
    config(['help.enabled' => true]);
    expect([hsgAsk($this, $refused), hsgAsk($this, $refused), hsgAsk($this, $refused)])->toBe([200, 200, 200]);
});

test('a new account, or one with an unverified email, may ask once an hour -- with its own wording', function () {
    $new = User::factory()->adult()->create(['created_at' => now()->subHours(2)]);
    $unverified = hsgEstablished(['email_verified_at' => null]);
    $established = hsgEstablished();

    expect([hsgAsk($this, $new), hsgAsk($this, $new)])->toBe([200, 429])
        ->and([hsgAsk($this, $unverified), hsgAsk($this, $unverified)])->toBe([200, 429])
        ->and([hsgAsk($this, $established), hsgAsk($this, $established), hsgAsk($this, $established), hsgAsk($this, $established)])->toBe([200, 200, 200, 429]);

    $this->actingAs($new)->postJson(route('help.requests.store'))->assertJsonPath('message', fn ($message) => str_contains($message, 'a short time ago'));
    $this->actingAs($established)->postJson(route('help.requests.store'))->assertJsonPath('message', fn ($message) => str_contains($message, 'several times'));
});

test('a username-only account is new only by age; just past it, or with no creation time, the rules hold', function () {
    $usernameOnly = hsgEstablished(['email' => null, 'email_verified_at' => null]);
    $justPast = hsgEstablished(['created_at' => now()->subHours(25)]);
    $noCreationTime = hsgEstablished();
    $noCreationTime->forceFill(['created_at' => null])->saveQuietly();

    expect([hsgAsk($this, $usernameOnly), hsgAsk($this, $usernameOnly)])->toBe([200, 200])
        ->and([hsgAsk($this, $justPast), hsgAsk($this, $justPast)])->toBe([200, 200])
        ->and([hsgAsk($this, $noCreationTime), hsgAsk($this, $noCreationTime)])->toBe([200, 429]);
});

test('the strictest limit wins, and a limit set to 0 never shuts anyone out', function () {
    $suspendedAndNew = User::factory()->adult()->create(['created_at' => now()->subHour()]);
    $report = new Report(['description' => 'Something happened']);
    $report->addedby()->associate(hsgEstablished());
    $report->reportable()->associate(GroupTherapy::factory()->create());
    $report->save();
    Suspension::query()->forceCreate(['user_id' => $suspendedAndNew->id, 'report_id' => $report->id, 'scope' => 'account', 'public_reason' => 'Conduct', 'starts_at' => now()->subMinute()]);

    config(['help.suspended_requests_per_hour' => 2, 'help.new_account_requests_per_hour' => 1]);
    expect([hsgAsk($this, $suspendedAndNew), hsgAsk($this, $suspendedAndNew)])->toBe([200, 429]);

    config(['help.new_account_requests_per_hour' => 0]);
    expect(hsgAsk($this, User::factory()->adult()->create()))->toBe(200);
});

test('past an account bucket\'s ring cap a request is still made, marked and alerted at once -- and an administrator rings it', function () {
    config(['help.rings_per_minute' => 2]);
    Event::fake([HelpRequestRingingEvent::class]);
    Notification::fake();
    Log::spy();
    hsgOnCall();
    $admin = User::factory()->adult()->has(Administrator::factory())->create();

    foreach (range(1, 3) as $i) {
        $this->actingAs(hsgEstablished())->postJson(route('help.requests.store'))->assertOk();
    }

    expect(HelpRequest::query()->whereStatus(HelpRequestStatusEnum::waiting)->count())->toBe(3);
    Event::assertDispatchedTimes(HelpRequestRingingEvent::class, 2);
    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => str_contains($message, 'ring cap') && $context['bucket'] === 'established')->once();

    $unrung = HelpRequest::query()->whereNotNull('ring_skipped_at')->sole();
    // Administrators alerted straight away, not after the usual wait.
    expect($unrung->escalated_at)->not->toBeNull();
    Notification::assertSentTo($admin, HelpRequestWaitingNotification::class);

    // First in their queue, marked.
    $queue = $this->actingAs($admin)->get(route('administrator.help_requests'))->viewData('page')['props'];
    $first = collect($queue)->flatten(1)->first(fn ($row) => is_array($row) && ($row['ringSkipped'] ?? false));
    expect($first['id'] ?? null)->toBe($unrung->id);

    // "Ring again" isn't capped, and clears the mark.
    $this->actingAs($admin)->postJson(route('admin.help_requests.ring', $unrung->id))->assertOk();
    Event::assertDispatchedTimes(HelpRequestRingingEvent::class, 3);
    expect($unrung->fresh()->ring_skipped_at)->toBeNull();
});

test('a flood of new accounts can\'t use up the ring cap for established ones', function () {
    config(['help.rings_per_minute' => 2, 'help.requests_per_hour_per_address' => 100]);
    Event::fake([HelpRequestRingingEvent::class]);
    hsgOnCall();

    foreach (range(1, 4) as $i) {
        $this->actingAs(User::factory()->adult()->create())->postJson(route('help.requests.store'))->assertOk();
    }
    Event::assertDispatchedTimes(HelpRequestRingingEvent::class, 2);

    $this->actingAs(hsgEstablished())->postJson(route('help.requests.store'))->assertOk();
    Event::assertDispatchedTimes(HelpRequestRingingEvent::class, 3);
});

test('a request the cap didn\'t ring is still offered to a counsellor who looks; nobody on call uses up nothing', function () {
    config(['help.rings_per_minute' => 1]);
    Event::fake([HelpRequestRingingEvent::class]);

    // Nobody on call: no ring, and the cap untouched.
    $this->actingAs(hsgEstablished())->postJson(route('help.requests.store'))->assertOk();
    expect(RateLimiter::attempts('help-rings:established'))->toBe(0);

    $counsellor = hsgOnCall();
    $this->actingAs(hsgEstablished())->postJson(route('help.requests.store'))->assertOk();
    $skipped = $this->actingAs(hsgEstablished())->postJson(route('help.requests.store'))->assertOk()->json('helpRequest.id');
    expect(HelpRequest::query()->find($skipped)->ring_skipped_at)->not->toBeNull();

    $ringing = $this->actingAs($counsellor->user)->getJson(route('help.requests.ringing'))->assertOk()->json();
    expect(collect($ringing)->flatten(1)->pluck('id')->filter()->all())->toContain($skipped);
});

test('a request that expired unanswered doesn\'t count against the person', function () {
    $person = User::factory()->adult()->create(['created_at' => now()->subHour()]);
    $id = $this->actingAs($person)->postJson(route('help.requests.store'))->assertOk()->json('helpRequest.id');
    HelpRequest::query()->whereKey($id)->update(['created_at' => now()->subMinutes((int) config('help.expire_after_minutes') + 1)]);

    SweepHelpRequestsAction::new()->execute();

    expect(HelpRequest::query()->find($id)->status)->toBe(HelpRequestStatusEnum::expired);
    $this->actingAs($person)->postJson(route('help.requests.store'))->assertOk();
});

test('the health check reports a request left waiting past its expiry, and nothing about it', function () {
    SchedulerHeartbeat::beat();
    $this->getJson(route('health.scheduler'))->assertOk()->assertJsonPath('helpRequestsOverdue', false);

    $person = hsgEstablished();
    $request = HelpRequest::query()->create(['user_id' => $person->id, 'active_user_id' => $person->id, 'status' => HelpRequestStatusEnum::waiting->value]);
    $request->forceFill(['created_at' => now()->subMinutes((int) config('help.expire_after_minutes') + 3)])->save();

    $this->getJson(route('health.scheduler'))->assertStatus(503)
        ->assertExactJsonStructure(['healthy', 'lastRunAt', 'helpRequestsOverdue'])
        ->assertJsonPath('healthy', false)->assertJsonPath('helpRequestsOverdue', true);
});
