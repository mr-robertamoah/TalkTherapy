<?php

use App\Actions\Help\PruneHelpChatTranscriptsAction;
use App\Actions\Safety\SendDueSafetyNoticesAction;
use App\Actions\Safety\SendSafetyFlagNoticeAction;
use App\Actions\User\AnonymiseClosedAccountAction;
use App\Enums\AdministratorTypeEnum;
use App\Enums\SafetyFlagNoticeStateEnum;
use App\Enums\SafetyFlagUrgencyEnum;
use App\Enums\TherapyStatusEnum;
use App\Events\HelpMessageSentEvent;
use App\Events\HelpQueueChangedEvent;
use App\Events\HelpRequestRingingEvent;
use App\Events\HelpRequestUpdatedEvent;
use App\Events\SafetyFlagsChangedEvent;
use App\Jobs\EscalateHelpRequestJob;
use App\Jobs\ReAlertSafetyFlagJob;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\Guardianship;
use App\Models\Report;
use App\Models\SafetyFlag;
use App\Models\SafetyFlagNote;
use App\Models\Suspension;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\GuardianSafetyNoticeNotification;
use App\Notifications\SafetyNoticeNotification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

// SCRUM-32 (TT-4.3c): the client's and a minor's guardians' notices -- held for the handling administrator,
// sent, held or withheld by them, sent on their own at the re-alert time, cancelled when the flag is
// withdrawn before anyone acknowledged it; the person's persistent notice; anonymisation; the help-chat
// hold. Helpers are file-local and sfn-prefixed (--parallel).

const SFN_REASON = 'Spoke of a plan to hurt themselves this week; needs a specialist assessment.';

function sfnAdmin(bool $super = false): User
{
    return User::factory()->adult()->has(Administrator::factory()->state([
        'type' => $super ? AdministratorTypeEnum::super->value : AdministratorTypeEnum::normal->value,
    ]))->create(['email_verified_at' => now()]);
}

// A minor (with a guardian) in a 1:1.
function sfnMinor(): array
{
    $minor = User::factory()->create(['dob' => now()->subYears(15)->toDateString(), 'firstName' => 'Kwame', 'email_verified_at' => now()]);
    $guardian = User::factory()->adult()->create(['email_verified_at' => now()]);
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $minor->id, 'ward_was_minor_at_creation' => true]);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $therapy = Therapy::factory()->create([
        'name' => 'Private Therapy Name', 'addedby_type' => User::class, 'addedby_id' => $minor->id, 'counsellor_id' => $counsellor->id,
        'status' => TherapyStatusEnum::in_session->value, 'anonymous' => false, 'public' => false,
    ]);

    return compact('minor', 'guardian', 'counsellor', 'therapy');
}

function sfnRaise($test, Counsellor $counsellor, Therapy $therapy, array $input = []): SafetyFlag
{
    $test->actingAs($counsellor->user)->postJson(route('api.safety_flags.therapy', ['therapyId' => $therapy->id]), [
        'urgency' => 'URGENT', 'reason' => SFN_REASON, 'discussedWithClient' => 'YES', 'emergencyServicesContacted' => false, ...$input,
    ])->assertCreated();

    return SafetyFlag::query()->latest('id')->first();
}

function sfnDecide($test, User $admin, SafetyFlag $flag, string $which, string $decision, ?string $reason = null)
{
    return $test->actingAs($admin)->postJson(route('admin.safety_flags.notice', ['flag' => $flag->public_id]), ['which' => $which, 'decision' => $decision, 'reason' => $reason]);
}

beforeEach(function () {
    config(['safety.enabled' => true]);
    Event::fake([SafetyFlagsChangedEvent::class]);
    Bus::fake([ReAlertSafetyFlagJob::class]);
});

test('nobody acknowledged it: the person and a minor\'s guardians are told at the re-alert time -- neutrally, never why, where or by whom', function () {
    Notification::fake();
    ['minor' => $minor, 'guardian' => $guardian, 'counsellor' => $counsellor, 'therapy' => $therapy] = sfnMinor();
    $flag = sfnRaise($this, $counsellor, $therapy);
    expect($flag->client_notice_state)->toBe(SafetyFlagNoticeStateEnum::pending)->and($flag->guardian_notice_state)->toBe(SafetyFlagNoticeStateEnum::pending);

    $this->travel(239)->minutes();
    expect(SendDueSafetyNoticesAction::new()->execute())->toBe(0);
    $this->travel(2)->minutes();
    expect(SendDueSafetyNoticesAction::new()->execute())->toBe(2)->and(SendDueSafetyNoticesAction::new()->execute())->toBe(0);

    Notification::assertSentTo($minor, SafetyNoticeNotification::class, fn ($notification, $channels) => $channels === ['database']);
    Notification::assertSentTo($guardian, GuardianSafetyNoticeNotification::class, function ($notification) use ($guardian, $counsellor) {
        $text = json_encode([$notification->toMail($guardian)->subject, $notification->toMail($guardian)->introLines, $notification->toArray($guardian)]);

        return str_contains($text, 'Kwame') && ! str_contains($text, 'Private Therapy') && ! str_contains($text, 'plan to hurt')
            && ! str_contains($text, (string) $counsellor->getName()) && ! str_contains($text, 'help chat');
    });
    expect($flag->fresh()->client_notice_state)->toBe(SafetyFlagNoticeStateEnum::sent);
});

test('the handler sends, holds or withholds each notice; held ones never go on their own; one left alone goes at the re-alert time after acknowledging', function () {
    Notification::fake();
    ['minor' => $minor, 'guardian' => $guardian, 'counsellor' => $counsellor, 'therapy' => $therapy] = sfnMinor();
    $flag = sfnRaise($this, $counsellor, $therapy);
    $handler = sfnAdmin();
    $this->actingAs($handler)->postJson(route('admin.safety_flags.acknowledge', ['flag' => $flag->public_id]))->assertOk();

    sfnDecide($this, sfnAdmin(), $flag, 'client', 'send')->assertForbidden();
    sfnDecide($this, $handler, $flag, 'client', 'hold')->assertStatus(422);
    sfnDecide($this, $handler, $flag, 'client', 'hold', 'Told me they are with an abusive partner; I will call first.')->assertOk();

    // Left alone, the guardians' goes at the re-alert time after acknowledging; the held one doesn't.
    $this->travel(241)->minutes();
    SendDueSafetyNoticesAction::new()->execute();
    Notification::assertSentTo($guardian, GuardianSafetyNoticeNotification::class);
    Notification::assertNotSentTo($minor, SafetyNoticeNotification::class);

    sfnDecide($this, $handler, $flag, 'client', 'send')->assertOk();
    Notification::assertSentTo($minor, SafetyNoticeNotification::class);
    sfnDecide($this, $handler, $flag, 'client', 'send')->assertStatus(409);
    // The reason is its own encrypted note, with its author.
    expect(DB::table('safety_flag_notes')->pluck('content')->implode(' '))->not->toContain('abusive')
        ->and(SafetyFlagNote::query()->where('kind', SafetyFlagNote::HOLD_PERSON)->value('content'))->toContain('abusive');
});

test('a counsellor\'s safeguarding reason holds the guardians\' notice; the handler\'s decision is final', function () {
    Notification::fake();
    ['guardian' => $guardian, 'counsellor' => $counsellor, 'therapy' => $therapy] = sfnMinor();
    $flag = sfnRaise($this, $counsellor, $therapy, ['guardianWithholdReason' => 'The concern is about the home.']);
    expect($flag->guardian_notice_state)->toBe(SafetyFlagNoticeStateEnum::held);

    $handler = sfnAdmin();
    $this->actingAs($handler)->postJson(route('admin.safety_flags.acknowledge', ['flag' => $flag->public_id]))->assertOk();
    $why = $this->actingAs($handler)->postJson(route('admin.safety_flags.reason', ['flag' => $flag->public_id]))->assertOk();
    expect($why->json('notes.0.content'))->toBe('The concern is about the home.')->and($why->json('notes.0.by'))->toBe($counsellor->user->name)
        ->and($why->json('notes.0.action'))->toBe('Why the notice to their guardians was held');
    // The history shows the hold as the counsellor's -- never their name.
    $history = collect($this->actingAs(sfnAdmin())->getJson(route('admin.safety_flags.list'))->json('flags.0.history'));
    expect($history->firstWhere('label', 'Notice to their guardians held')['by'])->toBe('the counsellor');

    $this->travel(5)->hours();
    SendDueSafetyNoticesAction::new()->execute();
    Notification::assertNotSentTo($guardian, GuardianSafetyNoticeNotification::class);

    sfnDecide($this, $handler, $flag, 'guardians', 'withhold', 'Agree: safeguarding concern at home; social services informed.')->assertOk();
    expect($flag->fresh()->guardian_notice_state)->toBe(SafetyFlagNoticeStateEnum::withheld)->and((int) $flag->fresh()->guardian_decided_by_id)->toBe($handler->id)
        // Both reasons kept, each with its author -- the counsellor's isn't overwritten.
        ->and(SafetyFlagNote::query()->whereIn('kind', [SafetyFlagNote::HOLD_GUARDIANS, SafetyFlagNote::WITHHOLD_GUARDIANS])->orderBy('id')->pluck('content')->all())
        ->toBe(['The concern is about the home.', 'Agree: safeguarding concern at home; social services informed.']);
});

test('a flag can\'t be resolved while a notice waits; an adult\'s, or a non-urgent one, tells no guardian', function () {
    Notification::fake();
    ['counsellor' => $counsellor, 'therapy' => $therapy] = sfnMinor();
    $flag = sfnRaise($this, $counsellor, $therapy);
    $handler = sfnAdmin();
    $this->actingAs($handler)->postJson(route('admin.safety_flags.acknowledge', ['flag' => $flag->public_id]))->assertOk();

    $resolve = fn () => $this->actingAs($handler)->postJson(route('admin.safety_flags.resolve', ['flag' => $flag->public_id]), ['outcome' => 'SAFETY_PLAN', 'note' => 'Plan agreed.']);
    $resolve()->assertStatus(409);
    sfnDecide($this, $handler, $flag, 'client', 'send')->assertOk();
    sfnDecide($this, $handler, $flag, 'guardians', 'withhold', 'Handled with the counsellor directly.')->assertOk();
    $resolve()->assertOk();

    $adult = User::factory()->adult()->create();
    $other = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $adult->id, 'counsellor_id' => $counsellor->id, 'status' => TherapyStatusEnum::in_session->value]);
    expect(sfnRaise($this, $counsellor, $other)->guardian_notice_state)->toBe(SafetyFlagNoticeStateEnum::notApplicable);
    ['counsellor' => $c2, 'therapy' => $t2] = sfnMinor();
    expect(sfnRaise($this, $c2, $t2, ['urgency' => 'NON_URGENT'])->guardian_notice_state)->toBe(SafetyFlagNoticeStateEnum::notApplicable);
});

test('withdrawn before anyone acknowledged it: its notices are cancelled; withdrawn after: they wait for the handler', function () {
    Notification::fake();
    ['minor' => $minor, 'counsellor' => $counsellor, 'therapy' => $therapy] = sfnMinor();
    $early = sfnRaise($this, $counsellor, $therapy);
    $this->actingAs($counsellor->user)->postJson(route('api.safety_flags.withdraw', ['flag' => $early->public_id]), ['reason' => 'Raised by mistake.'])->assertOk();
    expect($early->fresh()->client_notice_state)->toBe(SafetyFlagNoticeStateEnum::cancelled)->and($early->fresh()->guardian_notice_state)->toBe(SafetyFlagNoticeStateEnum::cancelled);

    $late = sfnRaise($this, $counsellor, $therapy);
    $this->actingAs(sfnAdmin())->postJson(route('admin.safety_flags.acknowledge', ['flag' => $late->public_id]))->assertOk();
    $this->actingAs($counsellor->user)->postJson(route('api.safety_flags.withdraw', ['flag' => $late->public_id]), ['reason' => 'Much calmer now.'])->assertOk();
    expect($late->fresh()->client_notice_state)->toBe(SafetyFlagNoticeStateEnum::pending);

    $this->travel(5)->hours();
    SendDueSafetyNoticesAction::new()->execute();
    Notification::assertNotSentTo($minor, SafetyNoticeNotification::class);
});

test('the person sees a fixed notice until they dismiss it (a suspended account too); a guardian\'s names only their ward', function () {
    ['minor' => $minor, 'guardian' => $guardian, 'counsellor' => $counsellor, 'therapy' => $therapy] = sfnMinor();
    $flag = sfnRaise($this, $counsellor, $therapy);
    $handler = sfnAdmin();
    $this->actingAs($handler)->postJson(route('admin.safety_flags.acknowledge', ['flag' => $flag->public_id]))->assertOk();
    sfnDecide($this, $handler, $flag, 'client', 'send')->assertOk();
    sfnDecide($this, $handler, $flag, 'guardians', 'send')->assertOk();

    // A suspended account still gets it (the route is open to them).
    $report = new Report(['description' => 'Conduct', 'data' => []]);
    $report->addedby()->associate(User::factory()->adult()->create());
    $report->reportable()->associate($minor);
    $report->save();
    Suspension::query()->forceCreate(['user_id' => $minor->id, 'report_id' => $report->id, 'scope' => 'account', 'starts_at' => now()->subHour(), 'public_reason' => 'A reason.']);

    $mine = $this->actingAs($minor->fresh())->getJson(route('safety_notice.current'))->assertOk();
    expect($mine->json('notice.kind'))->toBe('client')->and($mine->getContent())->not->toContain('plan to hurt')->not->toContain('Private Therapy')
        ->and(array_keys($mine->json('notice')))->toBe(['id', 'kind', 'message']);
    $theirs = $this->actingAs($guardian)->getJson(route('safety_notice.current'))->assertOk();
    expect($theirs->json('notice.message'))->toContain('Kwame')->and($theirs->getContent())->not->toContain('wardRef');

    // Someone else can't dismiss it; they can.
    $this->actingAs($guardian)->postJson(route('safety_notice.dismiss', ['notice' => $mine->json('notice.id')]))->assertOk();
    expect($this->actingAs($minor)->getJson(route('safety_notice.current'))->json('notice'))->not->toBeNull();
    $this->actingAs($minor)->postJson(route('safety_notice.dismiss', ['notice' => $mine->json('notice.id')]))->assertOk();
    expect($this->actingAs($minor)->getJson(route('safety_notice.current'))->json('notice'))->toBeNull();

    config(['safety.enabled' => false]);
    $this->actingAs($guardian)->getJson(route('safety_notice.current'))->assertNotFound();
});

test('closing the person\'s account clears every bit of free text about them in safety flags, and renames them in their guardians\' notices', function () {
    ['minor' => $minor, 'guardian' => $guardian, 'counsellor' => $counsellor, 'therapy' => $therapy] = sfnMinor();
    $flag = sfnRaise($this, $counsellor, $therapy);
    $handler = sfnAdmin();
    $this->actingAs($handler)->postJson(route('admin.safety_flags.acknowledge', ['flag' => $flag->public_id]))->assertOk();
    $this->actingAs($handler)->postJson(route('admin.safety_flags.note', ['flag' => $flag->public_id]), ['content' => 'Called the family.'])->assertOk();
    sfnDecide($this, $handler, $flag, 'guardians', 'send')->assertOk();

    expect(AnonymiseClosedAccountAction::new()->execute($minor->fresh()))->toBeTrue();

    $flag->refresh();
    foreach (SafetyFlag::FREE_TEXT as $column) {
        expect($flag->{$column})->toBeNull();
    }
    expect(SafetyFlagNote::query()->where('safety_flag_id', $flag->id)->value('content'))->toBeNull()
        ->and($flag->status->value)->toBe('ACKNOWLEDGED')
        ->and($guardian->notifications()->where('type', GuardianSafetyNoticeNotification::class)->first()->data['wardName'])->toBe(AnonymiseClosedAccountAction::CLOSED_ACCOUNT_NAME);
});

test('a help chat with a safety flag still being dealt with is kept past its one-year deletion', function () {
    Event::fake([SafetyFlagsChangedEvent::class, HelpRequestRingingEvent::class, HelpRequestUpdatedEvent::class, HelpQueueChangedEvent::class, HelpMessageSentEvent::class]);
    Bus::fake([ReAlertSafetyFlagJob::class, EscalateHelpRequestJob::class]);
    $person = User::factory()->adult()->create();
    $helper = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id, 'urgent_help_available_until' => now()->addHours(2)]);
    $id = $this->actingAs($person)->postJson(route('help.requests.store'))->json('helpRequest.id');
    $this->actingAs($helper->user)->postJson(route('help.requests.accept', ['helpRequest' => $id]))->assertOk();
    $this->actingAs($person)->postJson(route('help.requests.messages.store', ['helpRequest' => $id]), ['content' => 'Hello'])->assertOk();
    $this->actingAs($helper->user)->postJson(route('help.requests.safety_flag', ['helpRequest' => $id]), [
        'urgency' => 'IMMINENT', 'reason' => SFN_REASON, 'discussedWithClient' => 'YES', 'emergencyServicesContacted' => true,
    ])->assertCreated();
    $this->actingAs($person)->postJson(route('help.requests.end', ['helpRequest' => $id]))->assertOk();

    $this->travel(366)->days();
    expect(PruneHelpChatTranscriptsAction::new()->execute())->toBe(0);

    SafetyFlag::query()->update(['status' => 'RESOLVED', 'open_client_key' => null, 'client_notice_state' => 'SENT']);
    expect(PruneHelpChatTranscriptsAction::new()->execute())->toBe(1);
});

test('review fix: a notice held a moment before the sweep claims it is never sent; nor one on a flag withdrawn meanwhile', function () {
    Notification::fake();
    ['minor' => $minor, 'counsellor' => $counsellor, 'therapy' => $therapy] = sfnMinor();
    $flag = sfnRaise($this, $counsellor, $therapy);
    $handler = sfnAdmin();
    $this->actingAs($handler)->postJson(route('admin.safety_flags.acknowledge', ['flag' => $flag->public_id]))->assertOk();
    $this->travel(241)->minutes();

    // The sweep has loaded the flag (pending, due) ... then the handler holds it.
    $loadedBySweep = SafetyFlag::query()->find($flag->id);
    sfnDecide($this, $handler, $flag, 'client', 'hold', 'Calling them first.')->assertOk();
    $due = fn ($query) => $query->whereNoticesDue(SafetyFlagUrgencyEnum::urgent);
    expect(SendSafetyFlagNoticeAction::new()->execute($loadedBySweep, 'client', constrain: $due))->toBe('not_claimed')
        ->and($flag->fresh()->client_notice_state)->toBe(SafetyFlagNoticeStateEnum::held);

    // ... or the counsellor withdraws it: the guardians' notice (still pending) waits for the handler.
    $this->actingAs($counsellor->user)->postJson(route('api.safety_flags.withdraw', ['flag' => $flag->public_id]), ['reason' => 'Much better now.'])->assertOk();
    expect(SendSafetyFlagNoticeAction::new()->execute($loadedBySweep, 'guardians', constrain: $due))->toBe('not_claimed');
    Notification::assertNotSentTo($minor, SafetyNoticeNotification::class);
    Notification::assertNothingSentTo($minor);
});

test('review fix: nobody to send to is never "sent"; closing the account cancels what is still waiting', function () {
    Notification::fake();
    ['minor' => $minor, 'counsellor' => $counsellor, 'therapy' => $therapy] = sfnMinor();
    $flag = sfnRaise($this, $counsellor, $therapy);
    $handler = sfnAdmin();
    $this->actingAs($handler)->postJson(route('admin.safety_flags.acknowledge', ['flag' => $flag->public_id]))->assertOk();

    // Turned 18 since: no guardian is told.
    $minor->forceFill(['dob' => now()->subYears(18)->subDay()->toDateString()])->save();
    sfnDecide($this, $handler, $flag, 'guardians', 'send')->assertOk();
    expect($flag->fresh()->guardian_notice_state)->toBe(SafetyFlagNoticeStateEnum::noRecipient);
    Notification::assertNotSentTo(User::query()->get(), GuardianSafetyNoticeNotification::class);

    ['minor' => $other, 'counsellor' => $c2, 'therapy' => $t2] = sfnMinor();
    $second = sfnRaise($this, $c2, $t2);
    expect(AnonymiseClosedAccountAction::new()->execute($other->fresh()))->toBeTrue();
    expect($second->fresh()->client_notice_state)->toBe(SafetyFlagNoticeStateEnum::cancelled)
        ->and($second->fresh()->guardian_notice_state)->toBe(SafetyFlagNoticeStateEnum::cancelled);
});
