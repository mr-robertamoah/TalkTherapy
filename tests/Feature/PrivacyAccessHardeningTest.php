<?php

use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\TransactionStatusEnum;
use App\Events\DiscussionStartedEvent;
use App\Events\DiscussionUpdatedEvent;
use App\Events\SessionStartedEvent;
use App\Events\SessionTopicSetEvent;
use App\Events\SessionTopicUnsetEvent;
use App\Events\SessionUpdatedEvent;
use App\Exceptions\TherapyException;
use App\Http\Resources\GroupTherapyResource;
use App\Http\Resources\SessionResource;
use App\Models\AdminContentAccess;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\File;
use App\Models\GroupTherapy;
use App\Models\Message;
use App\Models\Report;
use App\Models\Request;
use App\Models\Session;
use App\Models\Suspension;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\SessionUpdatedNotification;
use App\Services\AppService;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// SCRUM-367 + SCRUM-263, 273, 144 (SCRUM-335 and SCRUM-95 are in their own unit tests).

function pahGroup(): array
{
    $creator = User::factory()->adult()->create();
    $member = User::factory()->adult()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $group = GroupTherapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $creator->id, 'public' => false,
        'payment_type' => 'PAID',
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 100, 'currency' => 'GHS', 'strictPaymentGate' => false, 'allowFreeHistoricalAccess' => true],
    ]);
    $group->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);
    $group->users()->attach($member->id, ['anonymous' => false, 'background_story' => 'x']);
    Transaction::factory()->create([
        'for_type' => GroupTherapy::class, 'for_id' => $group->id,
        'user_id' => $creator->id, 'status' => TransactionStatusEnum::success->value,
    ]);

    return compact('creator', 'member', 'counsellor', 'group');
}

function pahTherapy(): array
{
    $client = User::factory()->adult()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id, 'public' => false]);
    $session = Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class, 'status' => 'HELD', 'name' => 'Private session name']);

    return compact('client', 'counsellor', 'therapy', 'session');
}

function pahSuspend(User $user, User $reporter): void
{
    $report = new Report(['description' => 'x', 'data' => []]);
    $report->addedby()->associate($reporter);
    $report->save();
    Suspension::create(['user_id' => $user->id, 'report_id' => $report->id, 'scope' => 'account', 'starts_at' => now(), 'public_reason' => 'For the test.']);
}

// --- SCRUM-263 -------------------------------------------------------------------------------

test('a group\'s payment status is shown to its counsellors and admins, never to a member', function () {
    $g = pahGroup();
    $admin = User::factory()->has(Administrator::factory())->create();
    $statusFor = function (User $viewer) use ($g) {
        request()->setUserResolver(fn () => $viewer);

        return (new GroupTherapyResource($g['group']->fresh()))->resolve(request())['paymentStatus'];
    };

    expect($statusFor($g['member']))->toBeNull()
        ->and($statusFor($g['counsellor']->user))->toBe(TransactionStatusEnum::success->value)
        ->and($statusFor($admin))->toBe(TransactionStatusEnum::success->value);
});

test('a group session\'s payment status is hidden from members; an individual session\'s is not', function () {
    $g = pahGroup();
    $groupSession = Session::factory()->create(['for_id' => $g['group']->id, 'for_type' => GroupTherapy::class, 'payment_type' => 'PAID']);
    Transaction::factory()->create(['for_type' => Session::class, 'for_id' => $groupSession->id, 'user_id' => $g['creator']->id, 'status' => TransactionStatusEnum::success->value]);
    $t = pahTherapy();
    Transaction::factory()->create(['for_type' => Session::class, 'for_id' => $t['session']->id, 'user_id' => $t['client']->id, 'status' => TransactionStatusEnum::success->value]);
    $statusFor = function (User $viewer, Session $session) {
        request()->setUserResolver(fn () => $viewer);

        return (new SessionResource($session->fresh()))->resolve(request())['paymentStatus'];
    };

    expect($statusFor($g['member'], $groupSession))->toBeNull()
        ->and($statusFor($g['counsellor']->user, $groupSession))->toBe(TransactionStatusEnum::success->value)
        ->and($statusFor($t['counsellor']->user, $t['session']))->toBe(TransactionStatusEnum::success->value);
});

// --- SCRUM-273 -------------------------------------------------------------------------------

test('a group member who did not create it can neither update nor delete it', function () {
    $g = pahGroup();

    // The QA report (SCRUM-273) used a seeded member who is in fact the group's creator; a real
    // member gets the authorization refusal, not a validation error.
    $notAllowed = fn ($response) => expect(collect(session('errors')?->all() ?? [])->contains(TherapyException::NOT_ALLOWED))->toBeTrue();

    $notAllowed($this->actingAs($g['member'])->delete(route('group.therapies.delete', ['groupTherapyId' => $g['group']->id])));
    $notAllowed($this->actingAs($g['member'])->patch(route('group.therapies.update', ['groupTherapyId' => $g['group']->id]), ['name' => 'Taken over']));

    expect(GroupTherapy::find($g['group']->id))->not->toBeNull()
        ->and($g['group']->fresh()->name)->not->toBe('Taken over');
});

// --- SCRUM-144 -------------------------------------------------------------------------------

test('the group participant scopes agree, and never widen a caller\'s other conditions', function () {
    $g = pahGroup();
    $publicElsewhere = GroupTherapy::factory()->create(['public' => true]);

    foreach ([$g['creator'], $g['member'], $g['counsellor']->user] as $user) {
        expect(GroupTherapy::query()->whereParticipant($user)->pluck('id')->all())->toBe([$g['group']->id])
            ->and(GroupTherapy::query()->whereIsParticipant($user)->pluck('id')->all())->toBe([$g['group']->id])
            // public = true excludes the (private) group: the participant check must not OR past it.
            ->and(GroupTherapy::query()->where('public', true)->whereParticipant($user)->exists())->toBeFalse()
            ->and(GroupTherapy::query()->where('public', true)->whereCounsellor($g['counsellor'])->exists())->toBeFalse();
    }

    expect(GroupTherapy::query()->whereParticipant(User::factory()->create())->exists())->toBeFalse()
        ->and(method_exists(GroupTherapy::class, 'scopeWhereNotUser'))->toBeFalse()
        ->and($publicElsewhere->exists)->toBeTrue();
});

// --- SCRUM-367 #1: rate limits ---------------------------------------------------------------

test('every rate-limited route has its own counter, named after it, unless deliberately shared', function () {
    $shared = ['session-actions', 'image-uploads', 'counsellor-update', 'message-show', 'transactions-initiate', 'video-provider'];

    $wrong = collect(app('router')->getRoutes()->getRoutes())
        ->flatMap(fn ($route) => collect($route->gatherMiddleware())
            ->filter(fn ($m) => is_string($m) && preg_match('/^throttle:\d+,\d+(,|$)/', $m))
            ->map(fn ($m) => explode(',', $m)[2] ?? null)
            ->reject(fn ($prefix) => $prefix && ($prefix === $route->getName() || in_array($prefix, $shared, true)))
            ->map(fn () => $route->getName() ?? $route->uri()))
        ->values();

    expect($wrong->all())->toBe([]);
});

// --- SCRUM-367 #2: discussions are counsellors-only --------------------------------------------

test('discussion broadcasts go only to the discussion\'s counsellors, never the therapy\'s channel', function () {
    $t = pahTherapy();
    $other = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $discussion = Discussion::factory()->create([
        'for_id' => $t['therapy']->id, 'for_type' => Therapy::class,
        'addedby_type' => Counsellor::class, 'addedby_id' => $t['counsellor']->id,
    ]);
    $discussion->counsellors()->attach($other->id);

    foreach ([new DiscussionStartedEvent($discussion), new DiscussionUpdatedEvent($discussion)] as $event) {
        $names = collect($event->broadcastOn())->map(fn ($channel) => $channel->name)->sort()->values()->all();

        expect($names)->toBe(collect(["private-counsellors.{$t['counsellor']->id}", "private-counsellors.{$other->id}"])->sort()->values()->all())
            ->and(collect($event->broadcastOn())->every(fn ($c) => $c instanceof PrivateChannel))->toBeTrue();
    }
});

test('a discussion created by a user account reaches that user\'s counsellor profile', function () {
    $t = pahTherapy();
    $discussion = Discussion::factory()->create([
        'for_id' => $t['therapy']->id, 'for_type' => Therapy::class,
        'addedby_type' => User::class, 'addedby_id' => $t['counsellor']->user_id,
    ]);

    expect($discussion->participantCounsellorIds())->toBe([$t['counsellor']->id]);
});

test('a counsellor consulted only in a discussion can not join the client\'s therapy or group channel', function () {
    $t = pahTherapy();
    $g = pahGroup();
    $consultant = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    foreach ([[$t['therapy'], Therapy::class, $t['counsellor']], [$g['group'], GroupTherapy::class, $g['counsellor']]] as [$for, $type, $owner]) {
        Discussion::factory()->create(['for_id' => $for->id, 'for_type' => $type, 'addedby_type' => Counsellor::class, 'addedby_id' => $owner->id])
            ->counsellors()->attach($consultant->id);
    }

    expect(Broadcast::getChannels()->get('therapies.{therapyId}')($consultant->user, $t['therapy']->id))->toBeFalse()
        ->and(Broadcast::getChannels()->get('groupTherapies.{groupTherapyId}')($consultant->user, $g['group']->id))->toBeFalse()
        ->and((bool) Broadcast::getChannels()->get('therapies.{therapyId}')($t['client'], $t['therapy']->id))->toBeTrue();
});

test('discussion participation has one definition, and a creator\'s id never matches another type', function () {
    $t = pahTherapy();
    $byUser = Discussion::factory()->create(['for_id' => $t['therapy']->id, 'for_type' => Therapy::class, 'addedby_type' => User::class, 'addedby_id' => $t['counsellor']->user_id]);
    // A counsellor whose id equals the creating user's id must not count.
    $lookalike = Counsellor::factory()->create(['user_id' => User::factory()->create()->id]);
    $lookalikeMatches = $lookalike->id === $t['counsellor']->user_id
        ? Discussion::query()->whereIsParticipant($lookalike)->whereKey($byUser->id)->exists()
        : false;

    expect($byUser->isParticipant($t['counsellor']))->toBeTrue()
        ->and(Discussion::query()->whereIsParticipant($t['counsellor'])->whereKey($byUser->id)->exists())->toBeTrue()
        ->and($byUser->isParticipant($lookalike))->toBeFalse()
        ->and($lookalikeMatches)->toBeFalse();
});

// --- SCRUM-367 #3: session broadcasts announce only -------------------------------------------

test('session broadcasts carry the session id and nothing else', function () {
    $t = pahTherapy();
    $topic = $t['therapy']->topics()->create(['name' => 'Secret topic', 'description' => 'x', 'counsellor_id' => $t['counsellor']->id]);
    $t['session']->topics()->attach($topic->id);

    foreach ([new SessionStartedEvent($t['session']), new SessionUpdatedEvent($t['session']), new SessionTopicSetEvent($t['session']), new SessionTopicUnsetEvent($t['session'])] as $event) {
        expect($event->broadcastWith())->toBe(['sessionId' => $t['session']->id]);
    }

    expect((new SessionUpdatedNotification($t['session']))->toBroadcast($t['client'])->data)->toBe(['sessionId' => $t['session']->id]);
});

test('an announced session is fetched by a participant, refused to an outsider and a suspended participant', function () {
    $t = pahTherapy();
    $url = route('api.sessions.show', ['sessionId' => $t['session']->id]);

    $this->actingAs($t['client'])->getJson($url)->assertOk()->assertJsonPath('data.name', 'Private session name');
    $this->actingAs(User::factory()->create())->getJson($url)->assertNotFound();

    pahSuspend($t['counsellor']->user, $t['client']);
    $this->actingAs($t['counsellor']->user)->getJson($url)->assertForbidden();
});

test('a client who has not paid a strict-gated therapy can not fetch its session', function () {
    $t = pahTherapy();
    $t['therapy']->update(['payment_type' => 'PAID', 'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS', 'inPersonAmount' => 60, 'strictPaymentGate' => true]]);

    $this->actingAs($t['client'])->getJson(route('api.sessions.show', ['sessionId' => $t['session']->id]))->assertNotFound();
});

test('an admin fetching a private session they are not part of is recorded in the read log', function () {
    $t = pahTherapy();
    $admin = User::factory()->has(Administrator::factory())->create();

    $this->actingAs($admin)->getJson(route('api.sessions.show', ['sessionId' => $t['session']->id]))->assertOk();

    expect(AdminContentAccess::query()->where('admin_user_id', $admin->id)->where('resource', AdminContentAccess::SESSION)->count())->toBe(1);
});

// --- SCRUM-367 #4: closed accounts -----------------------------------------------------------

test('a closed account is anonymised after the retention period; its messages stay as "Deleted user"', function () {
    Storage::fake('public');
    $t = pahTherapy();
    $user = $t['client'];
    $user->update(['settings' => ['anonymous' => true], 'gender' => 'female', 'country' => 'Ghana']);
    Storage::disk('public')->put('avatars/me.jpg', 'me');
    $avatar = File::factory()->create(['name' => 'me.jpg', 'path' => 'avatars', 'storage' => 'public']);
    $user->avatarFile()->attach($avatar->id);
    $message = Message::factory()->create([
        'for_id' => $t['session']->id, 'for_type' => Session::class,
        'from_type' => User::class, 'from_id' => $user->id,
        'content' => 'still here', 'confidential' => false, 'type' => 'NORMAL',
    ]);
    $user->delete();
    $this->travel(config('accounts.closed_account_retention_days') + 1)->days();

    AppService::new()->anonymiseClosedAccounts();

    $user = User::withTrashed()->find($user->id);
    expect($user->anonymised_at)->not->toBeNull()
        ->and($user->only(['firstName', 'lastName', 'otherNames', 'username', 'email', 'dob', 'gender', 'country', 'settings']))
        ->each->toBeNull()
        ->and($user->name)->toBe('Deleted user')
        ->and(Storage::disk('public')->exists('avatars/me.jpg'))->toBeFalse()
        ->and($user->avatarFile()->count())->toBe(0)
        ->and(Message::find($message->id)->content)->toBe('still here')
        ->and(Message::find($message->id)->from->name)->toBe('Deleted user');
});

test('anonymising also clears date-of-birth requests, identity scans, tokens, notifications and the counsellor profile', function () {
    Storage::fake('identity_documents');
    Storage::fake('public');
    $user = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $user->id, 'name' => 'Dr Former', 'phone' => '0200000000']);
    $request = Request::query()->forceCreate([
        'from_type' => User::class, 'from_id' => $user->id, 'to_type' => User::class, 'to_id' => $user->id,
        'type' => RequestTypeEnum::ageVerification->value, 'status' => RequestStatusEnum::pending->value,
        'data' => ['attestedDob' => '2010-01-01', 'note' => 'kept'],
    ]);
    Storage::disk('identity_documents')->put('ids/scan.jpg', 'scan');
    $request->files()->attach(File::factory()->create(['name' => 'scan.jpg', 'path' => 'ids', 'storage' => 'identity_documents'])->id);
    $user->createToken('old');
    DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => 'x', 'notifiable_type' => User::class, 'notifiable_id' => $user->id, 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'x', 'created_at' => now()]);
    $counsellor->delete();
    $user->delete();
    $this->travel(config('accounts.closed_account_retention_days') + 1)->days();

    AppService::new()->anonymiseClosedAccounts();

    $request->refresh();
    expect($request->data)->toBe(['note' => 'kept'])
        ->and($request->status)->toBe(RequestStatusEnum::inconsequencial->value)
        ->and($request->files()->count())->toBe(0)
        ->and(Storage::disk('identity_documents')->exists('ids/scan.jpg'))->toBeFalse()
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count())->toBe(0)
        ->and(DB::table('notifications')->where('notifiable_id', $user->id)->count())->toBe(0)
        ->and(DB::table('password_reset_tokens')->count())->toBe(0)
        ->and(Counsellor::withTrashed()->find($counsellor->id)->only(['name', 'email', 'phone', 'about']))->each->toBeNull();
});

test('a closed account with an open report about it is held back', function () {
    $user = User::factory()->create(['firstName' => 'Reported']);
    $reporter = User::factory()->create();
    $report = new Report(['description' => 'x', 'data' => ['userId' => $user->id]]);
    $report->addedby()->associate($reporter);
    $report->save();
    $user->delete();
    $this->travel(config('accounts.closed_account_retention_days') + 1)->days();

    AppService::new()->anonymiseClosedAccounts();
    expect(User::withTrashed()->find($user->id)->anonymised_at)->toBeNull();

    $report->forceFill(['status' => 'dismissed'])->save();
    AppService::new()->anonymiseClosedAccounts();
    expect(User::withTrashed()->find($user->id)->anonymised_at)->not->toBeNull();
});

test('an open, a recently closed, or a suspended closed account is not anonymised', function () {
    $open = User::factory()->create(['firstName' => 'Open']);
    $suspended = User::factory()->create(['firstName' => 'Suspended']);
    pahSuspend($suspended, $open);
    $suspended->delete();
    $this->travel(config('accounts.closed_account_retention_days') + 1)->days();
    $recent = User::factory()->create(['firstName' => 'Recent']);
    $recent->delete();

    AppService::new()->anonymiseClosedAccounts();

    expect(User::withTrashed()->find($open->id)->firstName)->toBe('Open')
        ->and(User::withTrashed()->find($recent->id)->firstName)->toBe('Recent')
        ->and(User::withTrashed()->find($suspended->id)->firstName)->toBe('Suspended');
});
