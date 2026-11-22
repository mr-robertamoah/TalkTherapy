<?php

use App\Actions\Help\CloseHelpRequestAction;
use App\Actions\Session\AfterSessionCreatedAction;
use App\Actions\Session\ChangeSessionStatusAction;
use App\Actions\Transaction\RecordRefundStatusAction;
use App\DTOs\CreateCaseDTO;
use App\DTOs\CreateDiscussionDTO;
use App\DTOs\CreateLanguageDTO;
use App\DTOs\CreatePostDTO;
use App\DTOs\CreateProfessionDTO;
use App\DTOs\CreateReligionDTO;
use App\DTOs\CreateSessionDTO;
use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Enums\DiscussionStatusEnum;
use App\Enums\HelpRequestStatusEnum;
use App\Enums\RefundStatusEnum;
use App\Enums\RefundStatusSourceEnum;
use App\Enums\SessionStatusEnum;
use App\Enums\StarSourceEnum;
use App\Enums\TransactionStatusEnum;
use App\Events\HelpMessageSentEvent;
use App\Events\HelpRequestRingingEvent;
use App\Events\HelpRequestUpdatedEvent;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\HelpRequest;
use App\Models\Message;
use App\Models\Organization;
use App\Models\OrganizationInvoice;
use App\Models\OrganizationInvoiceLine;
use App\Models\Post;
use App\Models\Refund;
use App\Models\Session;
use App\Models\Star;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use App\Services\DiscussionService;
use App\Services\LanguageService;
use App\Services\PostService;
use App\Services\ProfessionService;
use App\Services\ReligionService;
use App\Services\TherapyCaseService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

// TT-7.11b/SCRUM-471: the new star rules -- held sessions (help calls included), held discussions with 2+ senders,
// posts (3 a UTC day) -- their revocation (a session gone bad or refunded, a discussion abandoned or failed), and the
// old award points being gone. Helpers are file-local and nsr-prefixed (--parallel).

beforeEach(function () {
    Notification::fake();
});

function nsrCounsellor(): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()]);
}

// A 1:1 therapy's session at $status, and its client.
function nsrSession(?Counsellor $counsellor = null, string $status = SessionStatusEnum::held_confirmation->value): array
{
    $client = User::factory()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class,
        'addedby_id' => $client->id,
        'counsellor_id' => ($counsellor ?? nsrCounsellor())->id,
    ]);
    $session = Session::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id, 'status' => $status]);

    return [$session, $client, $therapy];
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

function nsrRefund(Transaction $transaction, ?int $amount = null): Refund
{
    return Refund::factory()->create([
        'transaction_id' => $transaction->id,
        'amount' => $amount ?? $transaction->amount,
        'status' => RefundStatusEnum::processing->value,
    ]);
}

function nsrRecord(Refund $refund, RefundStatusEnum $status): void
{
    RecordRefundStatusAction::new()->execute($refund, $status->value, RefundStatusSourceEnum::webhook->value);
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
        $message = Message::factory()->make();
        $message->from()->associate($sender);
        $message->for()->associate($discussion);
        $message->save();
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

// --- Sessions -------------------------------------------------------------------------------------------------------

test('a held session earns its counsellor one star, and ending it again earns no more', function () {
    $counsellor = nsrCounsellor();
    [$session, $client] = nsrSession($counsellor);

    nsrHold($session, $client);
    nsrChange($session, $client, SessionStatusEnum::held);

    $star = nsrStars(StarSourceEnum::sessionHeld, $counsellor)->sole();
    expect($star->award_key)->toBe("session_held:{$session->id}")
        ->and($star->starreable_type)->toBe(Session::class)
        ->and($star->starreable_id)->toBe($session->id);
});

test('a session waiting for its held confirmation earns nothing yet', function () {
    $counsellor = nsrCounsellor();
    [$session, $client] = nsrSession($counsellor, SessionStatusEnum::in_session->value);

    nsrChange($session, $client, SessionStatusEnum::held);

    expect(nsrStars(StarSourceEnum::sessionHeld)->count())->toBe(0);
});

test('reopening a held session and ending it again earns no second star', function () {
    $counsellor = nsrCounsellor();
    [$session, $client] = nsrSession($counsellor);
    nsrHold($session, $client);

    nsrChange($session, $client, SessionStatusEnum::in_session);
    nsrChange($session, $client, SessionStatusEnum::in_session);
    nsrHold($session, $client);

    expect(nsrStars(StarSourceEnum::sessionHeld, $counsellor)->count())->toBe(1);
});

test('each active counsellor of a group session gets one star; an inactive one gets none', function () {
    [$a, $b, $inactive] = [nsrCounsellor(), nsrCounsellor(), nsrCounsellor()];
    $group = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $a->id]);
    $group->counsellors()->attach($a->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'ADMIN']);
    $group->counsellors()->attach($b->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);
    $group->counsellors()->attach($inactive->id, ['state' => CounsellorGroupTherapyStateEnum::inactive->value, 'role' => 'NORMAL']);
    $session = Session::factory()->create([
        'for_type' => GroupTherapy::class, 'for_id' => $group->id, 'status' => SessionStatusEnum::held_confirmation->value,
    ]);

    nsrHold($session, $a->user);
    nsrChange($session, $a->user, SessionStatusEnum::held);

    expect(nsrStars(StarSourceEnum::sessionHeld)->where('award_key', "session_held:{$session->id}")
        ->pluck('starred_id')->sort()->values()->all())->toBe([$a->id, $b->id]);
});

test('closing a connected help chat stars its counsellor once', function () {
    config(['help.new_account_requests_per_hour' => config('help.requests_per_hour')]);
    Event::fake([HelpRequestRingingEvent::class, HelpRequestUpdatedEvent::class, HelpMessageSentEvent::class]);
    $person = User::factory()->adult()->create();
    $counsellor = Counsellor::factory()->create([
        'user_id' => User::factory()->adult()->create()->id, 'urgent_help_available_until' => now()->addHours(2),
    ]);
    $help = $this->actingAs($person)->postJson(route('help.requests.store'))->assertOk()->json('helpRequest');
    $this->actingAs($counsellor->user)->postJson(route('help.requests.accept', ['helpRequest' => $help['id']]))->assertOk();
    $request = HelpRequest::query()->find($help['id']);

    expect(CloseHelpRequestAction::new()->execute($request, HelpRequestStatusEnum::connected, HelpRequestStatusEnum::ended, 'person'))->toBeTrue()
        ->and(CloseHelpRequestAction::new()->execute($request->fresh(), HelpRequestStatusEnum::connected, HelpRequestStatusEnum::ended, 'person'))->toBeFalse();

    $star = nsrStars(StarSourceEnum::sessionHeld, $counsellor)->sole();
    expect($star->award_key)->toBe("session_held:{$request->session_id}")
        ->and(Session::query()->find($request->session_id)->status)->toBe(SessionStatusEnum::held->value);
});

test('a held session that later ends failed or abandoned loses its star', function (SessionStatusEnum $end) {
    $counsellor = nsrCounsellor();
    [$session, $client] = nsrSession($counsellor);
    [$other, $otherClient] = nsrSession($counsellor);
    nsrHold($session, $client);
    nsrHold($other, $otherClient);

    nsrChange($session, $client, SessionStatusEnum::in_session);
    nsrChange($session, $client, SessionStatusEnum::in_session);
    nsrChange($session, $client, $end);

    expect(nsrStars(StarSourceEnum::sessionHeld, $counsellor)->pluck('award_key')->all())
        ->toBe(["session_held:{$other->id}"]);
})->with([SessionStatusEnum::failed, SessionStatusEnum::abandoned]);

// --- Refunds --------------------------------------------------------------------------------------------------------

test('a successful refund of a per-session payment takes back that session\'s star only', function () {
    $counsellor = nsrCounsellor();
    [$session, $client, $therapy] = nsrSession($counsellor);
    $sibling = Session::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id, 'status' => SessionStatusEnum::held_confirmation->value]);
    nsrHold($session, $client);
    nsrHold($sibling, $client);
    $transaction = Transaction::factory()->create([
        'for_type' => Session::class, 'for_id' => $session->id, 'user_id' => $client->id, 'status' => TransactionStatusEnum::success->value,
    ]);
    $refund = nsrRefund($transaction);

    nsrRecord($refund, RefundStatusEnum::success);
    // A webhook retry: already SUCCESS, so a no-op.
    nsrRecord($refund->fresh(), RefundStatusEnum::success);

    expect($refund->fresh()->status)->toBe(RefundStatusEnum::success->value)
        ->and(nsrStars(StarSourceEnum::sessionHeld, $counsellor)->pluck('award_key')->all())->toBe(["session_held:{$sibling->id}"]);
});

test('a successful refund of a per-therapy payment takes back every session\'s star of that therapy', function () {
    $counsellor = nsrCounsellor();
    [$session, $client, $therapy] = nsrSession($counsellor);
    $second = Session::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id, 'status' => SessionStatusEnum::held_confirmation->value]);
    [$elsewhere, $elsewhereClient] = nsrSession($counsellor);
    nsrHold($session, $client);
    nsrHold($second, $client);
    nsrHold($elsewhere, $elsewhereClient);
    $transaction = Transaction::factory()->create([
        'for_type' => Therapy::class, 'for_id' => $therapy->id, 'user_id' => $client->id, 'status' => TransactionStatusEnum::success->value,
    ]);
    $refund = nsrRefund($transaction);

    nsrRecord($refund, RefundStatusEnum::success);
    nsrRecord($refund->fresh(), RefundStatusEnum::success);

    expect(nsrStars(StarSourceEnum::sessionHeld, $counsellor)->pluck('award_key')->all())->toBe(["session_held:{$elsewhere->id}"]);
});

test('a successful refund of an org-financed payment takes back its sessions\' stars', function () {
    $counsellor = nsrCounsellor();
    [$session, $client, $therapy] = nsrSession($counsellor);
    nsrHold($session, $client);
    $transaction = Transaction::factory()->create([
        'for_type' => Therapy::class, 'for_id' => $therapy->id, 'user_id' => $client->id,
        'organization_id' => Organization::factory()->create()->id, 'status' => TransactionStatusEnum::success->value,
    ]);

    nsrRecord(nsrRefund($transaction), RefundStatusEnum::success);

    expect(nsrStars(StarSourceEnum::sessionHeld, $counsellor)->count())->toBe(0);
});

test('a successful refund of a retainer settlement takes back the stars of the sessions on its invoice', function () {
    $counsellor = nsrCounsellor();
    [$session, $client] = nsrSession($counsellor);
    [$notInvoiced, $otherClient] = nsrSession($counsellor);
    nsrHold($session, $client);
    nsrHold($notInvoiced, $otherClient);
    $invoice = OrganizationInvoice::factory()->create();
    OrganizationInvoiceLine::factory()->create([
        'organization_invoice_id' => $invoice->id, 'session_id' => $session->id, 'counsellor_id' => $counsellor->id,
    ]);
    $transaction = Transaction::factory()->create([
        'for_type' => OrganizationInvoice::class, 'for_id' => $invoice->id, 'user_id' => $client->id,
        'status' => TransactionStatusEnum::success->value,
    ]);

    nsrRecord(nsrRefund($transaction), RefundStatusEnum::success);

    expect(nsrStars(StarSourceEnum::sessionHeld, $counsellor)->pluck('award_key')->all())->toBe(["session_held:{$notInvoiced->id}"]);
});

test('a refund that has not succeeded keeps the star', function (RefundStatusEnum $status) {
    $counsellor = nsrCounsellor();
    [$session, $client] = nsrSession($counsellor);
    nsrHold($session, $client);
    $transaction = Transaction::factory()->create([
        'for_type' => Session::class, 'for_id' => $session->id, 'user_id' => $client->id, 'status' => TransactionStatusEnum::success->value,
    ]);
    $refund = Refund::factory()->create(['transaction_id' => $transaction->id, 'amount' => $transaction->amount, 'status' => RefundStatusEnum::pending->value]);

    nsrRecord($refund, $status);

    expect(nsrStars(StarSourceEnum::sessionHeld, $counsellor)->count())->toBe(1);
})->with([RefundStatusEnum::processing, RefundStatusEnum::failed]);

test('a partial refund keeps the star', function () {
    $counsellor = nsrCounsellor();
    [$session, $client] = nsrSession($counsellor);
    nsrHold($session, $client);
    $transaction = Transaction::factory()->create([
        'for_type' => Session::class, 'for_id' => $session->id, 'user_id' => $client->id,
        'amount' => 10000, 'status' => TransactionStatusEnum::success->value,
    ]);

    nsrRecord(nsrRefund($transaction, 4000), RefundStatusEnum::success);

    expect(nsrStars(StarSourceEnum::sessionHeld, $counsellor)->count())->toBe(1);
});

// --- Discussions ----------------------------------------------------------------------------------------------------

test('a held discussion with messages from one person earns nothing', function () {
    $creator = nsrCounsellor();
    // The same person as their user and as their counsellor profile is one sender.
    $discussion = nsrDiscussion($creator, [$creator, $creator, $creator->user]);

    DiscussionService::new()->endDiscussion(nsrDiscussionDTO($discussion, $creator->user));

    expect($discussion->fresh()->status)->toBe(DiscussionStatusEnum::held->value)
        ->and(nsrStars(StarSourceEnum::discussionHeld)->count())->toBe(0);
});

test('a held discussion with messages from two people stars each counsellor who ran it, once', function () {
    $creator = nsrCounsellor();
    $added = nsrCounsellor();
    $discussion = nsrDiscussion($creator, [$creator, $added], $added);

    DiscussionService::new()->endDiscussion(nsrDiscussionDTO($discussion, $creator->user));
    DiscussionService::new()->endDiscussion(nsrDiscussionDTO($discussion, $creator->user));

    expect(nsrStars(StarSourceEnum::discussionHeld)->where('award_key', "discussion_held:{$discussion->id}")
        ->pluck('starred_id')->sort()->values()->all())->toBe(collect([$creator->id, $added->id])->sort()->values()->all());
});

test('a held discussion later abandoned or failed loses its stars', function (string $method) {
    $creator = nsrCounsellor();
    $added = nsrCounsellor();
    $discussion = nsrDiscussion($creator, [$creator, $added], $added);
    $other = nsrDiscussion($creator, [$creator, $added]);
    DiscussionService::new()->endDiscussion(nsrDiscussionDTO($discussion, $creator->user));
    DiscussionService::new()->endDiscussion(nsrDiscussionDTO($other, $creator->user));

    // Failing is only allowed before its end time.
    $discussion->update(['end_time' => now()->addHour()]);
    DiscussionService::new()->{$method}(nsrDiscussionDTO($discussion, $creator->user));

    expect(nsrStars(StarSourceEnum::discussionHeld)->pluck('award_key')->unique()->values()->all())
        ->toBe(["discussion_held:{$other->id}"]);
})->with(['abandonDiscussion', 'failDiscussion']);

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

test('a post made as a user, not a counsellor, earns nothing', function () {
    $admin = User::factory()->has(Administrator::factory())->create();

    $post = PostService::new()->createPost(CreatePostDTO::new()->fromArray(['user' => $admin, 'content' => 'A post']));

    expect($post->addedby)->toBeInstanceOf(User::class)
        ->and(Star::query()->count())->toBe(0);
});

test('deleting a post deletes its star', function () {
    $counsellor = nsrCounsellor();
    $post = nsrPost($counsellor);
    expect(nsrStars(StarSourceEnum::postPublished, $counsellor)->count())->toBe(1);

    PostService::new()->deletePost(CreatePostDTO::new()->fromArray([
        'user' => $counsellor->user, 'post' => $post, 'addedby' => $counsellor,
    ]));

    expect(nsrStars(StarSourceEnum::postPublished, $counsellor)->count())->toBe(0);
});

// --- The old award points -------------------------------------------------------------------------------------------

test('the old award points give no stars', function () {
    $counsellor = nsrCounsellor();
    $user = $counsellor->user;
    [$session, $client] = nsrSession($counsellor, SessionStatusEnum::pending->value);

    AfterSessionCreatedAction::new()->execute($session, $user);
    LanguageService::new()->createLanguage(CreateLanguageDTO::new()->fromArray(['user' => $user, 'addedby' => $counsellor, 'name' => 'Nsr language']));
    ProfessionService::new()->createProfession(CreateProfessionDTO::new()->fromArray(['user' => $user, 'addedby' => $counsellor, 'name' => 'Nsr profession']));
    ReligionService::new()->createReligion(CreateReligionDTO::new()->fromArray(['user' => $user, 'addedby' => $counsellor, 'name' => 'Nsr religion']));
    TherapyCaseService::new()->createCase(CreateCaseDTO::new()->fromArray(['user' => $user, 'addedby' => $counsellor, 'name' => 'Nsr case']));

    expect(Star::query()->count())->toBe(0);
});
