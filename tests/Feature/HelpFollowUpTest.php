<?php

use App\Actions\Help\PruneHelpChatTranscriptsAction;
use App\Actions\Help\SweepHelpRequestsAction;
use App\Actions\Message\EnsureUserCanAccessTherapyContentAction;
use App\Actions\User\AnonymiseClosedAccountAction;
use App\Enums\AdministratorTypeEnum;
use App\Enums\HelpRequestStatusEnum;
use App\Events\HelpMessageSentEvent;
use App\Events\HelpQueueChangedEvent;
use App\Events\HelpRequestRingingEvent;
use App\Events\HelpRequestUpdatedEvent;
use App\Jobs\EscalateHelpRequestJob;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\Guardianship;
use App\Models\HelpRequest;
use App\Models\Message;
use App\Models\MessageNote;
use App\Models\Report;
use App\Models\Request;
use App\Models\Session;
use App\Models\SessionNote;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\HelpTherapyInviteNotification;
use App\Support\WardRef;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

// SCRUM-33 (TT-4.4c): after a help chat -- the invitation to a regular therapy, the one-year retention,
// and the last edge cases. Helpers are file-local and hfu-prefixed (--parallel).

function hfuCounsellor(): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id, 'urgent_help_available_until' => now()->addHours(2)]);
}

function hfuAdmin(): User
{
    return User::factory()->adult()->has(Administrator::factory()->state(['type' => AdministratorTypeEnum::normal->value]))->create();
}

// A help chat between a new person and $counsellor, with two messages; ended unless $end is false.
function hfuChat($test, Counsellor $counsellor, bool $end = true, ?User $person = null): HelpRequest
{
    $person ??= User::factory()->adult()->create();
    $counsellor->forceFill(['urgent_help_available_until' => now()->addHours(2)])->save();
    $id = $test->actingAs($person)->postJson(route('help.requests.store'))->assertOk()->json('helpRequest.id');
    $test->actingAs($counsellor->user)->postJson(route('help.requests.accept', ['helpRequest' => $id]))->assertOk();
    $test->actingAs($person)->postJson(route('help.requests.messages.store', ['helpRequest' => $id]), ['content' => 'Hello'])->assertOk();
    $test->actingAs($counsellor->user)->postJson(route('help.requests.messages.store', ['helpRequest' => $id]), ['content' => 'I am here'])->assertOk();
    if ($end) {
        $test->actingAs($person)->postJson(route('help.requests.end', ['helpRequest' => $id]))->assertOk();
    }

    return HelpRequest::query()->find($id);
}

beforeEach(function () {
    Event::fake([HelpRequestRingingEvent::class, HelpRequestUpdatedEvent::class, HelpMessageSentEvent::class, HelpQueueChangedEvent::class]);
    Bus::fake([EscalateHelpRequestJob::class]);
});

test('the counsellor invites the person to a regular therapy after the chat, once; the person is told who with', function () {
    Notification::fake();
    $counsellor = hfuCounsellor();
    $live = hfuChat($this, $counsellor, end: false);

    // Not while the chat is still going.
    $this->actingAs($counsellor->user)->postJson(route('help.requests.invite', ['helpRequest' => $live->id]))->assertStatus(422);
    expect($this->actingAs($counsellor->user)->getJson(route('help.requests.show', ['helpRequest' => $live->id]))->json('helpRequest.canInvite'))->toBeFalse();

    $this->actingAs($live->user)->postJson(route('help.requests.end', ['helpRequest' => $live->id]))->assertOk();
    expect($this->actingAs($counsellor->user)->getJson(route('help.requests.show', ['helpRequest' => $live->id]))->json('helpRequest.canInvite'))->toBeTrue();

    $this->actingAs($counsellor->user)->postJson(route('help.requests.invite', ['helpRequest' => $live->id]))->assertOk()
        ->assertJsonPath('helpRequest.canInvite', false);
    $this->actingAs($counsellor->user)->postJson(route('help.requests.invite', ['helpRequest' => $live->id]))->assertStatus(409);

    // Who with, and nothing from the chat.
    Notification::assertSentTo($live->user, HelpTherapyInviteNotification::class, fn ($notification) => $notification->toArray($live->user) === [
        'type' => 'help_request.therapy_invite', 'helpRequestId' => $live->id, 'counsellorName' => $counsellor->getName(), 'byCounsellor' => true,
        // SCRUM-396: who it names, so anonymising the counsellor's closed account renames them here.
        WardRef::KEY => WardRef::of($counsellor->user),
    ]);
    $this->actingAs($live->user)->getJson(route('help.requests.show', ['helpRequest' => $live->id]))
        ->assertJsonPath('helpRequest.invite.counsellorId', $counsellor->id)
        ->assertJsonPath('helpRequest.invite.counsellorName', $counsellor->getName());
    // The counsellor sees that they invited -- and the invitation is no more than that: no therapy yet.
    expect($this->actingAs($counsellor->user)->getJson(route('help.requests.show', ['helpRequest' => $live->id]))->json('helpRequest.invite'))->toBeNull()
        ->and(Therapy::query()->where('addedby_type', User::class)->where('addedby_id', $live->user_id)->where('is_help_chat', false)->count())->toBe(0);
});

test('nobody else can invite: not the person, not another counsellor, not an involved administrator', function () {
    $counsellor = hfuCounsellor();
    $help = hfuChat($this, $counsellor);

    // The same answer as a chat that doesn't exist -- and an administrator can't use the counsellor's route.
    $this->actingAs($help->user)->postJson(route('help.requests.invite', ['helpRequest' => $help->id]))->assertStatus(404);
    $this->actingAs(hfuCounsellor()->user)->postJson(route('help.requests.invite', ['helpRequest' => $help->id]))->assertStatus(404);
    $this->actingAs($counsellor->user)->postJson(route('help.requests.invite', ['helpRequest' => 999999]))->assertStatus(404);
    $this->actingAs(hfuAdmin())->postJson(route('help.requests.invite', ['helpRequest' => $help->id]))->assertStatus(404);

    // An administrator who asked for help themselves is involved: they can't invite themselves.
    $adminPerson = hfuAdmin();
    $theirs = hfuChat($this, $counsellor, person: $adminPerson);
    $this->actingAs($adminPerson)->postJson(route('admin.help_requests.invite', ['helpRequest' => $theirs->id]))->assertStatus(403);
    expect($theirs->fresh()->invited_at)->toBeNull();
    expect($help->fresh()->invited_at)->toBeNull();
});

test('an administrator invites from the queue, which shows it; a counsellor who can no longer take clients can\'t be the one', function () {
    Notification::fake();
    $admin = hfuAdmin();
    $counsellor = hfuCounsellor();
    $help = hfuChat($this, $counsellor);

    $row = fn () => collect($this->actingAs($admin)->getJson(route('admin.help_requests.queue'))->json('requests'))->firstWhere('id', $help->id);
    expect($row()['canInvite'])->toBeTrue();

    $this->actingAs($admin)->postJson(route('admin.help_requests.invite', ['helpRequest' => $help->id]))->assertOk();
    expect($row()['canInvite'])->toBeFalse()->and($row()['invitedAt'])->not->toBeNull()
        ->and($help->fresh()->invited_by_id)->toBe($admin->id);
    // Worded as our team's suggestion, not the counsellor's own invitation.
    Notification::assertSentTo($help->user, HelpTherapyInviteNotification::class, fn ($notification) => $notification->toArray($help->user)['byCounsellor'] === false);
    $this->actingAs($help->user)->getJson(route('help.requests.show', ['helpRequest' => $help->id]))->assertJsonPath('helpRequest.invite.byCounsellor', false);

    // Verification revoked since: no invitation with them.
    $other = hfuCounsellor();
    $later = hfuChat($this, $other);
    $this->actingAs($admin)->postJson(route('admin.help_requests.invite', ['helpRequest' => $later->id]))->assertOk();
    expect($this->actingAs($later->user)->getJson(route('help.requests.show', ['helpRequest' => $later->id]))->json('helpRequest.invite'))->not->toBeNull();
    $later->forceFill(['invited_at' => null])->save();
    $other->forceFill(['verified_at' => null])->save();
    // An invitation already sent isn't shown once they can't take clients.
    $later->forceFill(['invited_at' => now()])->save();
    expect($this->actingAs($later->user)->getJson(route('help.requests.show', ['helpRequest' => $later->id]))->json('helpRequest.invite'))->toBeNull();
    $later->forceFill(['invited_at' => null])->save();
    $this->actingAs($admin)->postJson(route('admin.help_requests.invite', ['helpRequest' => $later->id]))->assertStatus(422);
    $this->actingAs($other->user)->postJson(route('help.requests.invite', ['helpRequest' => $later->id]))->assertStatus(422);
});

test('a year after a chat ended its messages and notes are deleted; the record stays; a reported chat waits', function () {
    $counsellor = hfuCounsellor();
    $old = hfuChat($this, $counsellor);
    $old->forceFill(['note' => 'Can\'t cope', 'closed_note' => 'Called them'])->save();
    // A counsellor's notes on its messages -- one already deleted -- go too.
    $oldMessageIds = Message::query()->where('for_type', Session::class)->where('for_id', $old->session_id)->pluck('id');
    foreach ($oldMessageIds as $messageId) {
        MessageNote::query()->forceCreate(['message_id' => $messageId, 'counsellor_id' => $counsellor->id, 'content' => 'Private note']);
    }
    MessageNote::query()->where('message_id', $oldMessageIds->first())->first()->delete();
    SessionNote::query()->forceCreate(['session_id' => $old->session_id, 'counsellor_id' => $counsellor->id, 'content' => 'Private session note']);
    $reported = hfuChat($this, $counsellor);
    $report = new Report(['description' => 'Abusive']);
    $report->addedby()->associate($reported->user);
    $report->reportable()->associate(Message::query()->where('for_type', Session::class)->where('for_id', $reported->session_id)->first());
    $report->save();

    $this->travel(config('help.transcript_retention_days') - 1)->days();
    $recent = hfuChat($this, $counsellor);
    expect(PruneHelpChatTranscriptsAction::new()->execute())->toBe(0);

    $this->travel(2)->days();
    expect(PruneHelpChatTranscriptsAction::new()->execute())->toBe(1);

    $old->refresh();
    expect($old->transcript_deleted_at)->not->toBeNull()->and($old->note)->toBeNull()->and($old->closed_note)->toBeNull()
        ->and($old->status)->toBe(HelpRequestStatusEnum::ended)
        ->and(Message::withTrashed()->where('for_type', Session::class)->where('for_id', $old->session_id)->count())->toBe(0)
        ->and(MessageNote::withTrashed()->where('content', 'Private note')->count())->toBe(0)
        ->and(SessionNote::withTrashed()->where('content', 'Private session note')->count())->toBe(0)
        ->and(Message::query()->where('for_type', Session::class)->where('for_id', $reported->session_id)->count())->toBe(2)
        ->and(Message::query()->where('for_type', Session::class)->where('for_id', $recent->session_id)->count())->toBe(2);

    $this->actingAs($old->user)->getJson(route('help.requests.show', ['helpRequest' => $old->id]))->assertJsonPath('helpRequest.transcriptDeleted', true);
    expect($this->actingAs($old->user)->getJson(route('help.requests.messages', ['helpRequest' => $old->id]))->json('messages'))->toBe([]);
    $this->actingAs($counsellor->user)->postJson(route('help.requests.invite', ['helpRequest' => $old->id]))->assertStatus(422);

    // Once the report is resolved, it goes too.
    $report->forceFill(['status' => 'resolved'])->save();
    expect(PruneHelpChatTranscriptsAction::new()->execute())->toBe(1);
});

test('a retention setting that is missing a number or too short never prunes anything', function () {
    $help = hfuChat($this, hfuCounsellor());
    $this->travel(400)->days();

    foreach (['', 'one year', 0, 5] as $days) {
        config(['help.transcript_retention_days' => $days]);
        expect(PruneHelpChatTranscriptsAction::new()->execute())->toBe(0);
    }
    expect($help->fresh()->transcript_deleted_at)->toBeNull();
});

test('closing an account\'s anonymisation clears the notes on its help requests', function () {
    $person = User::factory()->adult()->create();
    $help = hfuChat($this, hfuCounsellor(), person: $person);
    $help->forceFill(['note' => 'Can\'t cope', 'closed_note' => 'Called them'])->save();

    expect(AnonymiseClosedAccountAction::new()->execute($person->fresh()))->toBeTrue();
    expect($help->fresh()->note)->toBeNull()->and($help->fresh()->closed_note)->toBeNull();
});

test('a connected chat whose session row is missing still ends once its time is up', function () {
    $help = hfuChat($this, hfuCounsellor(), end: false);
    Session::query()->whereKey($help->session_id)->forceDelete();

    expect(SweepHelpRequestsAction::new()->execute())->toMatchArray(['ended' => 0]);
    $this->travel(config('help.session_minutes') + 1)->minutes();
    expect(SweepHelpRequestsAction::new()->execute())->toMatchArray(['ended' => 1])
        ->and($help->fresh()->status)->toBe(HelpRequestStatusEnum::ended)
        ->and($help->fresh()->active_user_id)->toBeNull();
});

test('while a report about the chat is open nobody can invite; an involved administrator can\'t read the chat\'s messages', function () {
    $counsellor = hfuCounsellor();
    $guardianAdmin = hfuAdmin();
    $person = User::factory()->adult()->create();
    Guardianship::query()->create(['guardian_id' => $guardianAdmin->id, 'ward_id' => $person->id]);
    $help = hfuChat($this, $counsellor, person: $person);

    $report = new Report(['description' => 'Pressured me']);
    $report->addedby()->associate($person);
    $report->reportable()->associate(Session::query()->find($help->session_id));
    $report->save();

    $this->actingAs($counsellor->user)->postJson(route('help.requests.invite', ['helpRequest' => $help->id]))->assertStatus(422);
    $this->actingAs(hfuAdmin())->postJson(route('admin.help_requests.invite', ['helpRequest' => $help->id]))->assertStatus(422);

    $therapy = Therapy::query()->find($help->therapy_id);
    $session = Session::query()->find($help->session_id);
    expect(EnsureUserCanAccessTherapyContentAction::new()->execute($therapy, $guardianAdmin, $session))->toBeFalse()
        ->and(EnsureUserCanAccessTherapyContentAction::new()->execute($therapy, hfuAdmin(), $session))->toBeTrue();
});

test('the invitation goes once the person has asked that counsellor; the queue\'s "last day" is by when a chat ended', function () {
    $admin = hfuAdmin();
    $counsellor = hfuCounsellor();
    $help = hfuChat($this, $counsellor);
    $this->actingAs($counsellor->user)->postJson(route('help.requests.invite', ['helpRequest' => $help->id]))->assertOk();
    $invite = fn () => $this->actingAs($help->user)->getJson(route('help.requests.show', ['helpRequest' => $help->id]))->json('helpRequest.invite');
    expect($invite())->not->toBeNull();

    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $help->user_id, 'counsellor_id' => null, 'status' => 'PENDING']);
    Request::query()->forceCreate([
        'from_type' => User::class, 'from_id' => $help->user_id, 'to_type' => Counsellor::class, 'to_id' => $counsellor->id,
        'for_type' => Therapy::class, 'for_id' => $therapy->id, 'type' => 'THERAPY_ASSISTANCE_REQUEST', 'status' => 'PENDING', 'data' => [],
    ]);
    expect($invite())->toBeNull();

    // An old chat touched today (by the retention sweep) doesn't come back into "the last day".
    $this->travel(2)->days();
    $help->forceFill(['note' => null])->save();
    expect(collect($this->actingAs($admin)->getJson(route('admin.help_requests.queue'))->json('requests'))->pluck('id'))->not->toContain($help->id);
});
