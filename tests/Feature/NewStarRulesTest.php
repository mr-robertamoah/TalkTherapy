<?php

use App\Actions\Help\CloseHelpRequestAction;
use App\Actions\Help\SendHelpMessageAction;
use App\Actions\Session\AfterSessionCreatedAction;
use App\Actions\Session\ChangeSessionStatusAction;
use App\Actions\Transaction\RecordRefundStatusAction;
use App\DTOs\CreateCaseDTO;
use App\DTOs\CreateDiscussionDTO;
use App\DTOs\CreateLanguageDTO;
use App\DTOs\CreateLicensingAuthorityDTO;
use App\DTOs\CreatePostDTO;
use App\DTOs\CreateProfessionDTO;
use App\DTOs\CreateReligionDTO;
use App\DTOs\CreateSessionDTO;
use App\DTOs\CreateTherapyDTO;
use App\DTOs\CreateTherapyTopicDTO;
use App\DTOs\GroupTherapyDTO;
use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Enums\DiscussionStatusEnum;
use App\Enums\HelpRequestStatusEnum;
use App\Enums\RefundStatusEnum;
use App\Enums\RefundStatusSourceEnum;
use App\Enums\SessionAttendanceStatusEnum;
use App\Enums\SessionStatusEnum;
use App\Enums\SessionTypeEnum;
use App\Enums\SettingsEnum;
use App\Enums\StarSourceEnum;
use App\Enums\TransactionStatusEnum;
use App\Events\HelpMessageSentEvent;
use App\Events\HelpRequestRingingEvent;
use App\Events\HelpRequestUpdatedEvent;
use App\Exceptions\DiscussionException;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\HelpRequest;
use App\Models\Message;
use App\Models\Organization;
use App\Models\OrganizationInvoice;
use App\Models\OrganizationInvoiceLine;
use App\Models\PlatformSetting;
use App\Models\Post;
use App\Models\Refund;
use App\Models\Report;
use App\Models\Session;
use App\Models\SessionAttendance;
use App\Models\Star;
use App\Models\Suspension;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VideoSession;
use App\Models\VideoSessionParticipant;
use App\Services\DiscussionService;
use App\Services\GroupTherapyService;
use App\Services\LanguageService;
use App\Services\LicensingAuthorityService;
use App\Services\PostService;
use App\Services\ProfessionService;
use App\Services\ReligionService;
use App\Services\SessionService;
use App\Services\SettingsService;
use App\Services\TherapyCaseService;
use App\Services\TherapyService;
use App\Services\TherapyTopicService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

// TT-7.11b/SCRUM-471: the new star rules -- held sessions with proof both sides took part (help chats with their own
// rules), held discussions with 2+ senders, posts -- the daily caps, held being final, eligibility, and revocation (a
// session gone bad or refunded, a discussion abandoned or failed), and the old award points being gone. Helpers are
// file-local and nsr-prefixed (--parallel).

beforeEach(function () {
    Notification::fake();
});

function nsrCounsellor(): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()->adult()]);
}

function nsrAdmin(): User
{
    return User::factory()->has(Administrator::factory())->create();
}

// A 1:1 therapy's session at $status, which ran from an hour ago until five minutes ago, and its client.
function nsrSession(?Counsellor $counsellor = null, string $status = SessionStatusEnum::held_confirmation->value, ?User $client = null, array $state = []): array
{
    $client ??= User::factory()->adult()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => ($counsellor ?? nsrCounsellor())->id,
    ]);
    $session = Session::factory()->create([
        'for_type' => Therapy::class, 'for_id' => $therapy->id, 'status' => $status,
        'start_time' => now()->subHour(), 'end_time' => now()->subMinutes(5), ...$state,
    ]);

    return [$session, $client, $therapy];
}

function nsrMessage(Session|Discussion $for, User|Counsellor $from, ?string $at = null): Message
{
    $message = Message::factory()->make();
    $message->from()->associate($from);
    $message->for()->associate($for);
    $message->save();
    if ($at) {
        $message->forceFill(['created_at' => Carbon::parse($at)])->save();
    }

    return $message;
}

// Proof both sides took part: a message from each in the session's chat.
function nsrChat(Session $session, Counsellor $counsellor, User $client): void
{
    nsrMessage($session, $counsellor);
    nsrMessage($session, $client);
}

// A 1:1 session where both sides wrote, ready to be confirmed held.
function nsrAttendedSession(?Counsellor $counsellor = null, ?User $client = null, array $state = []): array
{
    $counsellor ??= nsrCounsellor();
    [$session, $client, $therapy] = nsrSession($counsellor, client: $client, state: $state);
    nsrChat($session, $counsellor, $client);

    return [$session, $client, $therapy, $counsellor];
}

function nsrChange(Session $session, User $user, SessionStatusEnum $status): Session
{
    return ChangeSessionStatusAction::new()->execute(
        CreateSessionDTO::new()->fromArray(['session' => $session->fresh(), 'user' => $user]),
        $status->value,
    );
}

// Held: a session at held_confirmation confirmed to held.
function nsrHold(Session $session, User $user): Session
{
    nsrChange($session, $user, SessionStatusEnum::held);
    $session = nsrChange($session, $user, SessionStatusEnum::held);
    expect($session->status)->toBe(SessionStatusEnum::held->value);

    return $session;
}

function nsrStars(StarSourceEnum $source, ?Counsellor $counsellor = null)
{
    return Star::query()
        ->where('source', $source->value)
        ->when($counsellor, fn ($q) => $q->where('starred_type', Counsellor::class)->where('starred_id', $counsellor->id));
}

// Suspended as a counsellor (as HelpRequestTest's hlpSuspend, counsellor scope).
function nsrSuspend(User $user): void
{
    $report = new Report(['description' => 'Something happened']);
    $report->addedby()->associate(User::factory()->adult()->create());
    $report->reportable()->associate(GroupTherapy::factory()->create());
    $report->save();
    Suspension::query()->forceCreate(['user_id' => $user->id, 'report_id' => $report->id, 'scope' => 'counsellor', 'public_reason' => 'Conduct', 'starts_at' => now()->subMinute()]);
}

function nsrSetting(SettingsEnum $key, int $value): void
{
    PlatformSetting::query()->forceCreate(['key' => $key->value, 'value' => (string) $value]);
}

function nsrPaid(Session|Therapy|GroupTherapy|OrganizationInvoice $for, User $payer, array $state = []): Transaction
{
    return Transaction::factory()->create([
        'for_type' => $for::class, 'for_id' => $for->id, 'user_id' => $payer->id,
        'status' => TransactionStatusEnum::success->value, ...$state,
    ]);
}

function nsrRefund(Transaction $transaction, ?int $amount = null, RefundStatusEnum $status = RefundStatusEnum::processing): Refund
{
    return Refund::factory()->create([
        'transaction_id' => $transaction->id,
        'amount' => $amount ?? $transaction->amount,
        'status' => $status->value,
    ]);
}

function nsrRecord(Refund $refund, RefundStatusEnum $status): void
{
    RecordRefundStatusAction::new()->execute($refund, $status->value, RefundStatusSourceEnum::webhook->value);
}

// A group with $active counsellors (and $inactive ones) and a member, with a session at held_confirmation.
function nsrGroupSession(array $active, array $inactive = []): array
{
    $group = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $active[0]->id]);
    foreach ($active as $i => $counsellor) {
        $group->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => $i ? 'NORMAL' : 'ADMIN']);
    }
    foreach ($inactive as $counsellor) {
        $group->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::inactive->value, 'role' => 'NORMAL']);
    }
    $member = User::factory()->create();
    $group->users()->attach($member->id, ['anonymous' => false]);
    $session = Session::factory()->create([
        'for_type' => GroupTherapy::class, 'for_id' => $group->id, 'status' => SessionStatusEnum::held_confirmation->value,
        'start_time' => now()->subHour(), 'end_time' => now()->subMinutes(5),
    ]);

    return [$session, $group, $member];
}

function nsrDiscussion(Counsellor $creator, array $senders, ?Counsellor $added = null): Discussion
{
    $discussion = Discussion::factory()->create([
        'addedby_type' => Counsellor::class,
        'addedby_id' => $creator->id,
        'for_id' => Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()])->id,
        'start_time' => now()->subHours(2),
        'end_time' => now()->subHour(),
        'status' => DiscussionStatusEnum::in_session->value,
    ]);
    if ($added) {
        $discussion->counsellors()->attach($added->id);
    }
    foreach ($senders as $sender) {
        nsrMessage($discussion, $sender, now()->subMinutes(90)->toDateTimeString());
    }

    return $discussion;
}

function nsrDiscussionDTO(Discussion $discussion, User $user): CreateDiscussionDTO
{
    return CreateDiscussionDTO::new()->fromArray(['discussion' => $discussion->fresh(), 'user' => $user]);
}

function nsrPost(Counsellor $counsellor): Post
{
    return PostService::new()->createPost(CreatePostDTO::new()->fromArray([
        'user' => $counsellor->user, 'addedby' => $counsellor, 'content' => 'A post',
    ]));
}

// A connected help chat answered by $counsellor: the person's opening note is its first message.
function nsrHelpChat($test, Counsellor $counsellor): HelpRequest
{
    config(['help.new_account_requests_per_hour' => config('help.requests_per_hour')]);
    Event::fake([HelpRequestRingingEvent::class, HelpRequestUpdatedEvent::class, HelpMessageSentEvent::class]);
    $counsellor->forceFill(['urgent_help_available_until' => now()->addHours(2)])->save();
    $person = User::factory()->adult()->create();
    $help = $test->actingAs($person)->postJson(route('help.requests.store'), ['note' => 'Please help'])->assertOk()->json('helpRequest');
    $test->actingAs($counsellor->user)->postJson(route('help.requests.accept', ['helpRequest' => $help['id']]))->assertOk();

    return HelpRequest::query()->find($help['id']);
}

function nsrCloseHelp(HelpRequest $request, string $endedBy = 'person'): bool
{
    return CloseHelpRequestAction::new()->execute($request->fresh(), HelpRequestStatusEnum::connected, HelpRequestStatusEnum::ended, $endedBy);
}

// --- Held sessions --------------------------------------------------------------------------------------------------

test('a held session where both sides wrote earns its counsellor one star, from the client; ending again earns no more', function () {
    [$session, $client, , $counsellor] = nsrAttendedSession();

    nsrHold($session, $client);
    nsrChange($session, $client, SessionStatusEnum::held);

    $star = nsrStars(StarSourceEnum::sessionHeld, $counsellor)->sole();
    expect($star->award_key)->toBe("session_held:{$session->id}")
        ->and($star->starreable_type)->toBe(Session::class)
        ->and($star->starreable_id)->toBe($session->id)
        ->and($star->starredby_type)->toBe(User::class)
        ->and($star->starredby_id)->toBe($client->id);
});

test('a session waiting for its held confirmation earns nothing yet', function () {
    [$session, $client] = nsrAttendedSession(state: ['status' => SessionStatusEnum::in_session->value]);

    nsrChange($session, $client, SessionStatusEnum::held);

    expect(nsrStars(StarSourceEnum::sessionHeld)->count())->toBe(0);
});

test('a held session with no proof both sides took part earns nothing', function (string $case) {
    $counsellor = nsrCounsellor();
    [$session, $client] = nsrSession($counsellor);
    match ($case) {
        'nothing' => null,
        'only the counsellor wrote' => nsrMessage($session, $counsellor),
        'only the client wrote' => nsrMessage($session, $client),
        'messages outside its time' => [nsrMessage($session, $counsellor, now()->subHours(3)->toDateTimeString()), nsrMessage($session, $client, now()->subHours(3)->toDateTimeString())],
        'only the counsellor joined the call' => VideoSessionParticipant::factory()->create([
            'video_session_id' => VideoSession::factory()->create(['session_id' => $session->id])->id, 'participant_id' => $counsellor->user_id,
        ]),
        'the client was marked absent' => SessionAttendance::query()->create([
            'session_id' => $session->id, 'user_id' => $client->id, 'status' => SessionAttendanceStatusEnum::absent->value, 'marked_at' => now(),
        ]),
    };

    nsrHold($session, $client);

    expect(nsrStars(StarSourceEnum::sessionHeld)->count())->toBe(0);
})->with(['nothing', 'only the counsellor wrote', 'only the client wrote', 'messages outside its time', 'only the counsellor joined the call', 'the client was marked absent']);

test('both sides joining the video call is proof', function () {
    $counsellor = nsrCounsellor();
    [$session, $client] = nsrSession($counsellor);
    $video = VideoSession::factory()->create(['session_id' => $session->id]);
    foreach ([$counsellor->user_id, $client->id] as $userId) {
        VideoSessionParticipant::factory()->create(['video_session_id' => $video->id, 'participant_id' => $userId]);
    }

    nsrHold($session, $client);

    expect(nsrStars(StarSourceEnum::sessionHeld, $counsellor)->count())->toBe(1);
});

test('an in-person session with the client marked present is proof', function () {
    $counsellor = nsrCounsellor();
    [$session, $client] = nsrSession($counsellor, state: ['type' => SessionTypeEnum::in_person->value]);
    SessionAttendance::query()->create([
        'session_id' => $session->id, 'user_id' => $client->id, 'status' => SessionAttendanceStatusEnum::present->value,
        'marked_by_counsellor_id' => $counsellor->id, 'marked_at' => now(),
    ]);

    nsrHold($session, $client);

    expect(nsrStars(StarSourceEnum::sessionHeld, $counsellor)->count())->toBe(1);
});

test('each active counsellor of a group session gets one star; an inactive one gets none', function () {
    [$a, $b, $inactive] = [nsrCounsellor(), nsrCounsellor(), nsrCounsellor()];
    [$session, , $member] = nsrGroupSession([$a, $b], [$inactive]);
    nsrChat($session, $b, $member);

    nsrHold($session, $a->user);
    nsrChange($session, $a->user, SessionStatusEnum::held);

    expect(nsrStars(StarSourceEnum::sessionHeld)->where('award_key', "session_held:{$session->id}")
        ->pluck('starred_id')->sort()->values()->all())->toBe([$a->id, $b->id]);
});

test('a paid session earns a star like a free one, but not once its payment was refunded', function () {
    [$paid, $client, , $counsellor] = nsrAttendedSession(state: ['payment_type' => 'PAID']);
    nsrPaid($paid, $client);
    [$refunded, $otherClient] = nsrAttendedSession($counsellor, state: ['payment_type' => 'PAID']);
    nsrRefund(nsrPaid($refunded, $otherClient), status: RefundStatusEnum::success);

    nsrHold($paid, $client);
    nsrHold($refunded, $otherClient);

    expect(nsrStars(StarSourceEnum::sessionHeld, $counsellor)->pluck('award_key')->all())->toBe(["session_held:{$paid->id}"]);
});

test('ineligible counsellors earn nothing: suspended, unverified or deleted', function (string $case) {
    [$session, $client, , $counsellor] = nsrAttendedSession();
    match ($case) {
        'unverified' => $counsellor->forceFill(['verified_at' => null])->save(),
        'revoked' => $counsellor->forceFill(['verification_revoked_at' => now()])->save(),
        'deleted' => $counsellor->delete(),
        'suspended' => nsrSuspend($counsellor->user),
    };

    nsrHold($session, $client);

    expect(Star::query()->count())->toBe(0);
})->with(['unverified', 'revoked', 'deleted', 'suspended']);

test('a failure awarding the star never blocks the session being held', function () {
    [$session, $client] = nsrAttendedSession();
    Schema::drop('stars');

    nsrHold($session, $client);

    expect($session->fresh()->status)->toBe(SessionStatusEnum::held->value);
});

// --- Held is final --------------------------------------------------------------------------------------------------

test('a participant can not abandon or reopen a held session, and its star stays', function (string $route) {
    [$session, $client, , $counsellor] = nsrAttendedSession();
    nsrHold($session, $client);

    foreach ([$client, $counsellor->user] as $user) {
        $this->actingAs($user)->postJson(route($route, ['sessionId' => $session->id]))
            ->assertStatus(422)->assertJsonPath('message', SessionService::HELD_IS_FINAL);
    }

    expect($session->fresh()->status)->toBe(SessionStatusEnum::held->value)
        ->and(nsrStars(StarSourceEnum::sessionHeld, $counsellor)->count())->toBe(1);
})->with(['api.sessions.abandon', 'api.sessions.in_session']);

test('nobody can mark a held session failed', function () {
    [$session, $client, , $counsellor] = nsrAttendedSession();
    nsrHold($session, $client);

    $this->actingAs($counsellor->user)->postJson(route('api.sessions.fail', ['sessionId' => $session->id]))
        ->assertStatus(422)->assertJsonPath('message', SessionService::CANNOT_FAIL);
    $this->actingAs(nsrAdmin())->postJson(route('api.sessions.fail', ['sessionId' => $session->id]))->assertStatus(422);

    expect(nsrStars(StarSourceEnum::sessionHeld, $counsellor)->count())->toBe(1);
});

test('an administrator can still abandon, or reopen and then abandon, a held session -- which takes its star back', function (bool $reopenFirst) {
    [$session, $client, , $counsellor] = nsrAttendedSession();
    [$other, $otherClient] = nsrAttendedSession($counsellor);
    nsrHold($session, $client);
    nsrHold($other, $otherClient);
    $admin = nsrAdmin();

    if ($reopenFirst) {
        $this->actingAs($admin)->postJson(route('api.sessions.in_session', ['sessionId' => $session->id]))->assertSuccessful();
        expect($session->fresh()->status)->toBe(SessionStatusEnum::in_session_confirmation->value);
    }
    $this->actingAs($admin)->postJson(route('api.sessions.abandon', ['sessionId' => $session->id]))->assertSuccessful();

    expect($session->fresh()->status)->toBe(SessionStatusEnum::abandoned->value)
        ->and(nsrStars(StarSourceEnum::sessionHeld, $counsellor)->pluck('award_key')->all())->toBe(["session_held:{$other->id}"]);
})->with(['abandon' => false, 'reopen, then abandon' => true]);

// --- Help chats -----------------------------------------------------------------------------------------------------

test('a help chat where both wrote, lasting long enough, stars its counsellor once, from the person', function () {
    $counsellor = nsrCounsellor();
    $request = nsrHelpChat($this, $counsellor);
    SendHelpMessageAction::new()->store($request, $counsellor->user, 'I am here.');
    $this->travel(6)->minutes();

    expect(nsrCloseHelp($request))->toBeTrue()
        ->and(nsrCloseHelp($request))->toBeFalse();

    $star = nsrStars(StarSourceEnum::helpChatHeld, $counsellor)->sole();
    expect($star->award_key)->toBe("help_chat_held:{$request->session_id}")
        ->and($star->starredby_id)->toBe($request->user_id)
        ->and(nsrStars(StarSourceEnum::sessionHeld)->count())->toBe(0);
});

test('a help chat earns nothing when it was too short, one-sided, or ended by suspension or revocation', function (string $case) {
    $counsellor = nsrCounsellor();
    $request = nsrHelpChat($this, $counsellor);
    if ($case !== 'the counsellor never replied') {
        SendHelpMessageAction::new()->store($request, $counsellor->user, 'I am here.');
    }
    $this->travel($case === 'too short' ? 3 : 6)->minutes();

    nsrCloseHelp($request, match ($case) {
        'ended by suspension' => 'suspension',
        'ended by revocation' => 'revoked',
        'the counsellor never replied' => 'time',
        default => 'person',
    });

    expect(Star::query()->count())->toBe(0);
})->with(['too short', 'the counsellor never replied', 'ended by suspension', 'ended by revocation']);

// --- Daily caps -----------------------------------------------------------------------------------------------------

test('session stars stop at the daily cap, and a new UTC day starts again', function () {
    Carbon::setTestNow('2026-12-10 20:00:00');
    nsrSetting(SettingsEnum::recognitionCapSession, 2);
    $counsellor = nsrCounsellor();

    foreach (range(1, 3) as $i) {
        [$session, $client] = nsrAttendedSession($counsellor);
        nsrHold($session, $client);
    }
    expect(nsrStars(StarSourceEnum::sessionHeld, $counsellor)->count())->toBe(2);

    Carbon::setTestNow('2026-12-11 00:00:01');
    [$session, $client] = nsrAttendedSession($counsellor);
    nsrHold($session, $client);

    expect(nsrStars(StarSourceEnum::sessionHeld, $counsellor)->count())->toBe(3);
});

test('the default session cap is 8 a day', function () {
    $counsellor = nsrCounsellor();

    foreach (range(1, 9) as $i) {
        [$session, $client] = nsrAttendedSession($counsellor);
        nsrHold($session, $client);
    }

    expect(nsrStars(StarSourceEnum::sessionHeld, $counsellor)->count())->toBe(8)
        ->and(SettingsService::new()->getSettingsForAdmin()['recognitionCaps'])->toBe([
            'session' => 8, 'help_chat' => 5, 'discussion' => 2, 'post' => 3, 'session_per_client' => 1,
        ]);
});

test('one client earns a counsellor at most one session star a day', function () {
    $counsellor = nsrCounsellor();
    $client = User::factory()->create();
    [$first] = nsrAttendedSession($counsellor, $client);
    [$second] = nsrAttendedSession($counsellor, $client);
    [$third, $otherClient] = nsrAttendedSession($counsellor);

    nsrHold($first, $client);
    nsrHold($second, $client);
    nsrHold($third, $otherClient);

    expect(nsrStars(StarSourceEnum::sessionHeld, $counsellor)->pluck('award_key')->sort()->values()->all())
        ->toBe(collect(["session_held:{$first->id}", "session_held:{$third->id}"])->sort()->values()->all());

    $this->travel(1)->days();
    [$fourth] = nsrAttendedSession($counsellor, $client);
    nsrHold($fourth, $client);
    expect(nsrStars(StarSourceEnum::sessionHeld, $counsellor)->count())->toBe(3);
});

test('an administrator\'s per-client setting is respected', function () {
    nsrSetting(SettingsEnum::recognitionCapSessionPerClient, 2);
    $counsellor = nsrCounsellor();
    $client = User::factory()->create();

    foreach (range(1, 3) as $i) {
        [$session] = nsrAttendedSession($counsellor, $client);
        nsrHold($session, $client);
    }

    expect(nsrStars(StarSourceEnum::sessionHeld, $counsellor)->count())->toBe(2);
});

// --- Refunds --------------------------------------------------------------------------------------------------------

test('a successful refund of a per-session payment takes back that session\'s star only; a retry does nothing more', function () {
    [$session, $client, $therapy, $counsellor] = nsrAttendedSession();
    $sibling = Session::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id, 'status' => SessionStatusEnum::held_confirmation->value, 'start_time' => now()->subHour()]);
    nsrMessage($sibling, $counsellor);
    nsrMessage($sibling, $client);
    nsrSetting(SettingsEnum::recognitionCapSessionPerClient, 5);
    nsrHold($session, $client);
    nsrHold($sibling, $client);
    $refund = nsrRefund(nsrPaid($session, $client));

    nsrRecord($refund, RefundStatusEnum::success);
    nsrRecord($refund->fresh(), RefundStatusEnum::success);

    expect($refund->fresh()->status)->toBe(RefundStatusEnum::success->value)
        ->and(nsrStars(StarSourceEnum::sessionHeld, $counsellor)->pluck('award_key')->all())->toBe(["session_held:{$sibling->id}"]);
});

test('a successful refund of a per-therapy payment takes back every session\'s star of that therapy', function () {
    nsrSetting(SettingsEnum::recognitionCapSessionPerClient, 5);
    [$session, $client, $therapy, $counsellor] = nsrAttendedSession();
    $second = Session::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id, 'status' => SessionStatusEnum::held_confirmation->value, 'start_time' => now()->subHour()]);
    nsrChat($second, $counsellor, $client);
    [$elsewhere, $elsewhereClient] = nsrAttendedSession($counsellor);
    $transaction = nsrPaid($therapy, $client);
    nsrHold($session, $client);
    nsrHold($second, $client);
    nsrHold($elsewhere, $elsewhereClient);

    nsrRecord(nsrRefund($transaction), RefundStatusEnum::success);

    expect(nsrStars(StarSourceEnum::sessionHeld, $counsellor)->pluck('award_key')->all())->toBe(["session_held:{$elsewhere->id}"]);
});

test('a successful refund of an org-financed payment takes back its sessions\' stars', function () {
    [$session, $client, $therapy, $counsellor] = nsrAttendedSession();
    $transaction = nsrPaid($therapy, $client, ['organization_id' => Organization::factory()->create()->id]);
    nsrHold($session, $client);
    expect(nsrStars(StarSourceEnum::sessionHeld, $counsellor)->count())->toBe(1);

    nsrRecord(nsrRefund($transaction), RefundStatusEnum::success);

    expect(nsrStars(StarSourceEnum::sessionHeld, $counsellor)->count())->toBe(0);
});

test('a successful refund of a retainer settlement takes back the stars of the sessions on its invoice', function () {
    [$session, $client, , $counsellor] = nsrAttendedSession();
    [$notInvoiced, $otherClient] = nsrAttendedSession($counsellor);
    nsrHold($session, $client);
    nsrHold($notInvoiced, $otherClient);
    $invoice = OrganizationInvoice::factory()->create();
    OrganizationInvoiceLine::factory()->create([
        'organization_invoice_id' => $invoice->id, 'session_id' => $session->id, 'counsellor_id' => $counsellor->id,
    ]);

    nsrRecord(nsrRefund(nsrPaid($invoice, $client)), RefundStatusEnum::success);

    expect(nsrStars(StarSourceEnum::sessionHeld, $counsellor)->pluck('award_key')->all())->toBe(["session_held:{$notInvoiced->id}"]);
});

test('a refund that has not succeeded, or only part of the payment, keeps the star', function (RefundStatusEnum $status, bool $partial) {
    [$session, $client, , $counsellor] = nsrAttendedSession();
    nsrHold($session, $client);
    $transaction = nsrPaid($session, $client, ['amount' => 10000]);
    $refund = Refund::factory()->create(['transaction_id' => $transaction->id, 'amount' => $partial ? 4000 : 10000, 'status' => RefundStatusEnum::pending->value]);

    nsrRecord($refund, $status);

    expect(nsrStars(StarSourceEnum::sessionHeld, $counsellor)->count())->toBe(1);
})->with([
    'processing' => [RefundStatusEnum::processing, false],
    'failed' => [RefundStatusEnum::failed, false],
    'partial' => [RefundStatusEnum::success, true],
]);

test('a group payment refunded while another member\'s payment stands keeps the star; the last payer\'s refund revokes it', function (bool $perTherapy) {
    [$a, $b] = [nsrCounsellor(), nsrCounsellor()];
    [$session, $group, $member] = nsrGroupSession([$a, $b]);
    $otherMember = User::factory()->create();
    $group->users()->attach($otherMember->id, ['anonymous' => false]);
    nsrChat($session, $a, $member);
    $for = $perTherapy ? $group : $session;
    $first = nsrPaid($for, $member);
    $second = nsrPaid($for, $otherMember);
    nsrHold($session, $a->user);
    expect(nsrStars(StarSourceEnum::sessionHeld)->count())->toBe(2);

    nsrRecord(nsrRefund($first), RefundStatusEnum::success);
    expect(nsrStars(StarSourceEnum::sessionHeld)->count())->toBe(2);

    nsrRecord(nsrRefund($second), RefundStatusEnum::success);
    expect(nsrStars(StarSourceEnum::sessionHeld)->count())->toBe(0);
})->with(['per session' => false, 'per therapy' => true]);

// --- Discussions ----------------------------------------------------------------------------------------------------

test('a held discussion with messages from one person earns nothing', function () {
    $creator = nsrCounsellor();
    // The same person as their user and as their counsellor profile is one sender.
    $discussion = nsrDiscussion($creator, [$creator, $creator, $creator->user]);

    DiscussionService::new()->endDiscussion(nsrDiscussionDTO($discussion, $creator->user));

    expect($discussion->fresh()->status)->toBe(DiscussionStatusEnum::held->value)
        ->and(nsrStars(StarSourceEnum::discussionHeld)->count())->toBe(0);
});

test('messages outside a discussion\'s start and end times don\'t count', function () {
    $creator = nsrCounsellor();
    $added = nsrCounsellor();
    $discussion = nsrDiscussion($creator, [$creator], $added);
    nsrMessage($discussion, $added, now()->subMinutes(30)->toDateTimeString());

    DiscussionService::new()->endDiscussion(nsrDiscussionDTO($discussion, $creator->user));

    expect(nsrStars(StarSourceEnum::discussionHeld)->count())->toBe(0);
});

test('a held discussion with messages from two people stars each counsellor who ran it', function () {
    $creator = nsrCounsellor();
    $added = nsrCounsellor();
    $discussion = nsrDiscussion($creator, [$creator, $added], $added);

    DiscussionService::new()->endDiscussion(nsrDiscussionDTO($discussion, $creator->user));

    expect($discussion->fresh()->held_at)->not->toBeNull()
        ->and(nsrStars(StarSourceEnum::discussionHeld)->where('award_key', "discussion_held:{$discussion->id}")
            ->pluck('starred_id')->sort()->values()->all())->toBe(collect([$creator->id, $added->id])->sort()->values()->all());
});

test('an ended discussion is final for its counsellors: no re-ending, abandoning, failing or reopening a held one', function (string $method) {
    $creator = nsrCounsellor();
    $added = nsrCounsellor();
    $discussion = nsrDiscussion($creator, [$creator, $added], $added);
    DiscussionService::new()->endDiscussion(nsrDiscussionDTO($discussion, $creator->user));
    $discussion->update(['end_time' => now()->addHour()]); // failing is only allowed before its end time

    expect(fn () => DiscussionService::new()->{$method}(nsrDiscussionDTO($discussion, $creator->user)))
        ->toThrow(DiscussionException::class);
    if ($method === 'endDiscussion') {
        return;
    }

    expect($discussion->fresh()->status)->toBe(DiscussionStatusEnum::held->value)
        ->and(nsrStars(StarSourceEnum::discussionHeld)->count())->toBe(2);
})->with(['abandonDiscussion', 'failDiscussion', 'getInDiscussion']);

test('re-ending a held discussion is refused for its counsellors', function () {
    $creator = nsrCounsellor();
    $discussion = nsrDiscussion($creator, [$creator]);
    DiscussionService::new()->endDiscussion(nsrDiscussionDTO($discussion, $creator->user));

    expect(fn () => DiscussionService::new()->endDiscussion(nsrDiscussionDTO($discussion, $creator->user)))
        ->toThrow(DiscussionException::class, DiscussionService::ALREADY_ENDED);
});

test('an administrator can abandon a held discussion, taking its stars back, and ending it again earns none', function () {
    $creator = nsrCounsellor();
    $added = nsrCounsellor();
    $discussion = nsrDiscussion($creator, [$creator, $added], $added);
    DiscussionService::new()->endDiscussion(nsrDiscussionDTO($discussion, $creator->user));
    $admin = nsrAdmin();

    DiscussionService::new()->abandonDiscussion(nsrDiscussionDTO($discussion, $admin));
    expect($discussion->fresh()->status)->toBe(DiscussionStatusEnum::abandoned->value)
        ->and(nsrStars(StarSourceEnum::discussionHeld)->count())->toBe(0);

    DiscussionService::new()->endDiscussion(nsrDiscussionDTO($discussion, $admin));
    expect($discussion->fresh()->status)->toBe(DiscussionStatusEnum::held->value)
        ->and(nsrStars(StarSourceEnum::discussionHeld)->count())->toBe(0);
});

test('a counsellor earns at most 2 discussion stars a day', function () {
    $creator = nsrCounsellor();
    $other = nsrCounsellor();

    foreach (range(1, 3) as $i) {
        DiscussionService::new()->endDiscussion(nsrDiscussionDTO(nsrDiscussion($creator, [$creator, $other]), $creator->user));
    }

    expect(nsrStars(StarSourceEnum::discussionHeld, $creator)->count())->toBe(2);
});

test('a discussion must start at least 30 minutes from now and end 30 minutes after it starts', function () {
    $creator = nsrCounsellor();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory(), 'counsellor_id' => $creator->id]);
    $create = fn ($start, $end) => DiscussionService::new()->createDiscussion(CreateDiscussionDTO::new()->fromArray([
        'user' => $creator->user, 'addedby' => $creator, 'for' => $therapy, 'name' => 'Case review', 'description' => 'About the case',
        'startTime' => $start, 'endTime' => $end,
    ]));

    expect(fn () => $create(now()->subHours(2), now()->subHour()))->toThrow(DiscussionException::class, 'at least 30 minutes from now')
        ->and(fn () => $create(now()->addMinutes(10), now()->addHour()))->toThrow(DiscussionException::class, 'at least 30 minutes from now')
        ->and(fn () => $create(null, now()->addHour()))->toThrow(DiscussionException::class)
        ->and(fn () => $create(now()->addHour(), now()->addHour()->addMinutes(10)))->toThrow(DiscussionException::class, 'at least 30 minutes from the start time');
    expect($create(now()->addHour(), now()->addHours(2)))->toBeInstanceOf(Discussion::class);

    $this->actingAs($creator->user)->postJson(route('api.discussions.create'), [
        'name' => 'Case review', 'description' => 'About the case', 'forId' => $therapy->id, 'for' => 'therapy',
        'startTime' => now()->subHour()->toIso8601String(), 'endTime' => now()->toIso8601String(),
    ])->assertStatus(422)->assertJsonValidationErrors(['startTime']);
});

// --- Posts ----------------------------------------------------------------------------------------------------------

test('a counsellor\'s posts earn a star each, up to 3 a UTC day', function () {
    Carbon::setTestNow('2026-12-10 23:00:00');
    $counsellor = nsrCounsellor();

    $posts = collect(range(1, 4))->map(fn () => nsrPost($counsellor));

    expect(nsrStars(StarSourceEnum::postPublished, $counsellor)->orderBy('id')->pluck('award_key')->all())
        ->toBe($posts->take(3)->map(fn ($post) => "post_published:{$post->id}")->all());

    // A new UTC day, a new allowance.
    Carbon::setTestNow('2026-12-11 00:00:01');
    $next = nsrPost($counsellor);

    expect(nsrStars(StarSourceEnum::postPublished, $counsellor)->count())->toBe(4)
        ->and(nsrStars(StarSourceEnum::postPublished, $counsellor)->where('award_key', "post_published:{$next->id}")->exists())->toBeTrue();
});

test('an administrator\'s post cap is respected', function () {
    nsrSetting(SettingsEnum::recognitionCapPost, 1);
    $counsellor = nsrCounsellor();

    nsrPost($counsellor);
    nsrPost($counsellor);

    expect(nsrStars(StarSourceEnum::postPublished, $counsellor)->count())->toBe(1);
});

test('a post made as a user, not a counsellor, earns nothing', function () {
    $post = PostService::new()->createPost(CreatePostDTO::new()->fromArray(['user' => nsrAdmin(), 'content' => 'A post']));

    expect($post->addedby)->toBeInstanceOf(User::class)
        ->and(Star::query()->count())->toBe(0);
});

test('deleting a post through the app deletes its star', function () {
    $counsellor = nsrCounsellor();
    $post = nsrPost($counsellor);
    expect(nsrStars(StarSourceEnum::postPublished, $counsellor)->count())->toBe(1);

    $this->actingAs($counsellor->user)->deleteJson(route('api.posts.delete', ['postId' => $post->id]))->assertSuccessful();

    expect(Post::query()->find($post->id))->toBeNull()
        ->and(nsrStars(StarSourceEnum::postPublished, $counsellor)->count())->toBe(0);
});

// --- The old award points -------------------------------------------------------------------------------------------

test('the old award points give no stars', function () {
    $counsellor = nsrCounsellor();
    $user = $counsellor->user;
    [$session, $client, $therapy] = nsrSession($counsellor, SessionStatusEnum::pending->value);

    AfterSessionCreatedAction::new()->execute($session, $user);
    LanguageService::new()->createLanguage(CreateLanguageDTO::new()->fromArray(['user' => $user, 'addedby' => $counsellor, 'name' => 'Nsr language']));
    ProfessionService::new()->createProfession(CreateProfessionDTO::new()->fromArray(['user' => $user, 'addedby' => $counsellor, 'name' => 'Nsr profession']));
    ReligionService::new()->createReligion(CreateReligionDTO::new()->fromArray(['user' => $user, 'addedby' => $counsellor, 'name' => 'Nsr religion']));
    TherapyCaseService::new()->createCase(CreateCaseDTO::new()->fromArray(['user' => $user, 'addedby' => $counsellor, 'name' => 'Nsr case']));
    LicensingAuthorityService::new()->createLicensingAuthority(CreateLicensingAuthorityDTO::new()->fromArray([
        'user' => $user, 'addedby' => $counsellor, 'name' => 'Nsr authority', 'type' => 'OTHER', 'licenseType' => 'NUMBER',
    ]));
    TherapyService::new()->createTherapy(CreateTherapyDTO::new()->fromArray([
        'user' => User::factory()->adult()->create(), 'name' => 'Nsr therapy', 'backgroundStory' => 'A story.', 'public' => true,
        'maxSessions' => 1, 'sessionType' => 'ONCE', 'anonymous' => false, 'allowInPerson' => false, 'paymentType' => 'FREE',
    ]));
    GroupTherapyService::new()->createGroupTherapy(GroupTherapyDTO::new()->fromArray([
        'user' => $user, 'counsellor' => $counsellor, 'name' => 'Nsr group', 'about' => 'A group', 'public' => true,
        'anonymous' => false, 'allowInPerson' => false, 'allowAnyone' => false, 'sessionType' => 'ONCE', 'paymentType' => 'FREE',
    ]));
    TherapyTopicService::new()->createTherapyTopic(CreateTherapyTopicDTO::new()->fromArray([
        'user' => $user, 'therapy' => $therapy, 'name' => 'Sleep', 'description' => 'About sleep',
    ]));
    DiscussionService::new()->createDiscussion(CreateDiscussionDTO::new()->fromArray([
        'user' => $user, 'addedby' => $counsellor, 'for' => $therapy, 'name' => 'Case review', 'description' => 'About the case',
        'startTime' => now()->addDay(), 'endTime' => now()->addDay()->addHour(),
    ]));

    expect(Star::query()->count())->toBe(0);
});
