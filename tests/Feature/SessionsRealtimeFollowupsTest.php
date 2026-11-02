<?php

use App\Enums\AlertStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\SessionStatusEnum;
use App\Enums\TransactionStatusEnum;
use App\Events\DiscussionStartedEvent;
use App\Events\SessionStartedEvent;
use App\Http\Resources\SessionResource;
use App\Http\Resources\TherapyResource;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\Request;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AppService;
use App\Services\RequestService;
use Illuminate\Support\Facades\Event;

// SCRUM-368 (payment status, /privacy periods) and SCRUM-369 (started broadcasts, active session).

function srfTherapy(array $attributes = []): array
{
    $client = User::factory()->adult()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $therapy = Therapy::factory()->create(array_merge([
        'addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id, 'public' => true,
    ], $attributes));

    return compact('client', 'counsellor', 'therapy');
}

function srfWait(User $user, $alertable): void
{
    $user->alerts()->create(['status' => AlertStatusEnum::waiting->value, 'alertable_type' => $alertable::class, 'alertable_id' => $alertable->id]);
}

// --- SCRUM-368: an individual therapy's payment status ---------------------------------------

test('a public therapy\'s payment and refund status are shown to its client, counsellor and admins only', function () {
    $t = srfTherapy();
    $session = Session::factory()->create(['for_id' => $t['therapy']->id, 'for_type' => Therapy::class, 'payment_type' => 'PAID']);
    Transaction::factory()->create(['for_type' => Therapy::class, 'for_id' => $t['therapy']->id, 'user_id' => $t['client']->id, 'status' => TransactionStatusEnum::success->value]);
    Transaction::factory()->create(['for_type' => Session::class, 'for_id' => $session->id, 'user_id' => $t['client']->id, 'status' => TransactionStatusEnum::success->value]);
    $admin = User::factory()->has(Administrator::factory())->create();
    $stranger = User::factory()->adult()->create();

    $seenBy = function (?User $viewer) use ($t, $session) {
        request()->setUserResolver(fn () => $viewer);

        return [
            (new TherapyResource($t['therapy']->fresh()))->resolve(request())['paymentStatus'],
            (new SessionResource($session->fresh()))->resolve(request())['paymentStatus'],
        ];
    };

    $paid = [TransactionStatusEnum::success->value, TransactionStatusEnum::success->value];
    expect($seenBy($t['client']))->toBe($paid)
        ->and($seenBy($t['counsellor']->user))->toBe($paid)
        ->and($seenBy($admin))->toBe($paid)
        ->and($seenBy($stranger))->toBe([null, null])
        ->and($seenBy(null))->toBe([null, null]);

    request()->setUserResolver(fn () => $stranger);
    expect((new TherapyResource($t['therapy']->fresh()))->resolve(request())['refundStatus'])->toBeNull();
});

// --- SCRUM-368: /privacy states the configured periods ---------------------------------------

test('the privacy page states the configured retention periods, read on each request', function () {
    config([
        'user.closed_account_retention_days' => 45,
        'counsellor.deletion_grace_period_days' => 90,
        'identity_verification.document_retention_days' => 14,
        'counsellor.license_rejected_retention_days' => 21,
        'counsellor.national_id_retention_days' => 7,
        'counsellor.license_abandoned_retention_days' => 60,
    ]);

    $this->get(route('privacy'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('Privacy')
        ->where('retentionDays', [
            'closedAccount' => 45, 'counsellorProfile' => 90, 'identityDocument' => 14,
            'licenseRejected' => 21, 'nationalId' => 7, 'licenseAbandoned' => 60,
        ]));
});

// --- SCRUM-369: the active session -----------------------------------------------------------

test('a group\'s active session is its own, never an individual therapy\'s that shares its id', function () {
    $group = GroupTherapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()->adult()->create()->id]);
    // What the old for_type = Therapy filter matched: a Therapy session whose for_id is the group's id.
    Session::factory()->create(['for_id' => $group->id, 'for_type' => Therapy::class, 'status' => SessionStatusEnum::in_session->value, 'start_time' => now()->subMinutes(5), 'end_time' => now()->addHour()]);

    expect($group->fresh()->activeSession)->toBeNull();

    $own = Session::factory()->create(['for_id' => $group->id, 'for_type' => GroupTherapy::class, 'status' => SessionStatusEnum::pending->value, 'start_time' => now()->subMinutes(5), 'end_time' => now()->addHour()]);

    expect($group->fresh()->activeSession?->id)->toBe($own->id);
});

test('a session abandoned inside its time window is no longer active', function () {
    $t = srfTherapy();
    $session = Session::factory()->create(['for_id' => $t['therapy']->id, 'for_type' => Therapy::class, 'status' => SessionStatusEnum::pending->value, 'start_time' => now()->subMinutes(5), 'end_time' => now()->addHour()]);

    expect($t['therapy']->fresh()->activeSession?->id)->toBe($session->id);

    $session->update(['status' => SessionStatusEnum::abandoned->value]);

    expect($t['therapy']->fresh()->activeSession)->toBeNull();
});

// --- SCRUM-369: started broadcasts -----------------------------------------------------------

test('session.started is broadcast once per session, however many people wait or minutes pass', function () {
    Event::fake([SessionStartedEvent::class, DiscussionStartedEvent::class]);
    $t = srfTherapy();
    Session::factory()->create(['for_id' => $t['therapy']->id, 'for_type' => Therapy::class, 'status' => SessionStatusEnum::in_session->value, 'start_time' => now()->subMinutes(5), 'end_time' => now()->addHour()]);

    srfWait($t['client'], $t['therapy']);
    srfWait($t['counsellor']->user, $t['therapy']);
    AppService::new()->broadcastStartedSessionsAndDiscussions();

    srfWait($t['client'], $t['therapy']);
    AppService::new()->broadcastStartedSessionsAndDiscussions();

    Event::assertDispatchedTimes(SessionStartedEvent::class, 1);
    expect($t['client']->alerts()->count() + $t['counsellor']->user->alerts()->count())->toBe(0);
});

test('discussion.started is broadcast for a therapy\'s discussion that has started', function () {
    Event::fake([SessionStartedEvent::class, DiscussionStartedEvent::class]);
    $t = srfTherapy();
    Discussion::factory()->create([
        'for_id' => $t['therapy']->id, 'for_type' => Therapy::class,
        'addedby_type' => Counsellor::class, 'addedby_id' => $t['counsellor']->id,
        'status' => 'PENDING', 'start_time' => now()->subMinutes(2), 'end_time' => now()->addHour(),
    ]);

    srfWait($t['counsellor']->user, $t['therapy']);
    AppService::new()->broadcastStartedSessionsAndDiscussions();

    Event::assertDispatchedTimes(DiscussionStartedEvent::class, 1);
    Event::assertNotDispatched(SessionStartedEvent::class);
});

test('a page loaded inside the five minutes before a session starts already shows it as active', function () {
    // The scheduler announces session.started once (from five minutes before the start), so a page
    // loaded after that must already show the session -- one definition of "active" for both.
    $t = srfTherapy(['public' => false]);
    $session = Session::factory()->create(['for_id' => $t['therapy']->id, 'for_type' => Therapy::class, 'status' => SessionStatusEnum::pending->value, 'start_time' => now()->addMinutes(3), 'end_time' => now()->addHour()]);

    expect($t['therapy']->activeSession?->id)->toBe($session->id)
        ->and($t['therapy']->getActiveSession($t['client'])?->id)->toBe($session->id)
        ->and($t['therapy']->getActiveSession($t['counsellor']->user)?->id)->toBe($session->id);
});

test('a group\'s discussion about to start is its active discussion', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $group = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id]);
    $discussion = Discussion::factory()->create([
        'for_id' => $group->id, 'for_type' => GroupTherapy::class,
        'addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id,
        'status' => 'PENDING', 'start_time' => now()->addMinutes(3), 'end_time' => now()->addHour(),
    ]);

    expect($group->activeDiscussion?->id)->toBe($discussion->id)
        ->and($group->getActiveDiscussion($counsellor)?->id)->toBe($discussion->id);

    $discussion->update(['status' => 'ABANDONED']);
    expect($group->fresh()->activeDiscussion)->toBeNull();
});

// --- SCRUM-142: the request listing's status filter ------------------------------------------

test('the status filter applies to every branch of the request listing', function () {
    $user = User::factory()->adult()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $user->id]);
    $other = User::factory()->adult()->create();
    $make = fn ($from, $to, string $status) => Request::factory()->for($from, 'from')->for($to, 'to')->create([
        'type' => RequestTypeEnum::counsellor->value, 'status' => $status, 'data' => [],
    ]);

    // One accepted request on each branch (as the user, as the user's counsellor profile).
    $make($user, $other, 'ACCEPTED');
    $make($other, $user, 'ACCEPTED');
    $make($counsellor, $other, 'ACCEPTED');
    $make($other, $counsellor, 'ACCEPTED');
    $pending = $make($other, $counsellor, 'PENDING');

    $listed = RequestService::new()->getRequests('PENDING', $user)->collection->map(fn ($r) => $r->resource->id)->all();

    expect($listed)->toBe([$pending->id]);
});
