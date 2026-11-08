<?php

use App\Actions\Safety\ReAlertSafetyFlagsAction;
use App\Enums\AdministratorTypeEnum;
use App\Enums\ConstantsEnum;
use App\Enums\SafetyFlagStatusEnum;
use App\Enums\SafetyFlagUrgencyEnum;
use App\Enums\TherapyStatusEnum;
use App\Events\HelpQueueChangedEvent;
use App\Events\HelpRequestRingingEvent;
use App\Events\HelpRequestUpdatedEvent;
use App\Events\SafetyFlagsChangedEvent;
use App\Jobs\EscalateHelpRequestJob;
use App\Jobs\ReAlertSafetyFlagJob;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\SafetyFlag;
use App\Models\SafetyFlagEvent;
use App\Models\SafetyFlagNote;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\SafetyFlagRaisedNotification;
use App\Notifications\SafetyFlagReAlertNotification;
use App\Notifications\SafetyFlagWithdrawnNotification;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

// SCRUM-32 (TT-4.3b): handling a safety flag -- notes, actions, resolving, the counsellor withdrawing it,
// the re-alert to super administrators, the fallback mailbox, and what counsellors see of a flag's
// status. Helpers are file-local and sfh-prefixed (--parallel).

const SFH_REASON = 'They have stopped eating and spoke of a plan; they need specialist care.';

function sfhAdmin(bool $super = false): User
{
    return User::factory()->adult()->has(Administrator::factory()->state([
        'type' => $super ? AdministratorTypeEnum::super->value : AdministratorTypeEnum::normal->value,
    ]))->create(['email_verified_at' => now()]);
}

function sfhTherapy(): array
{
    $client = User::factory()->adult()->create(['firstName' => 'Ama']);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create(['email_verified_at' => now()])->id]);
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id,
        'status' => TherapyStatusEnum::in_session->value, 'anonymous' => false, 'public' => false,
    ]);

    return compact('client', 'counsellor', 'therapy');
}

function sfhRaise($test, Counsellor $counsellor, Therapy $therapy, string $urgency = 'URGENT'): SafetyFlag
{
    $test->actingAs($counsellor->user)->postJson(route('api.safety_flags.therapy', ['therapyId' => $therapy->id]), [
        'urgency' => $urgency, 'reason' => SFH_REASON, 'discussedWithClient' => 'YES', 'emergencyServicesContacted' => false,
    ])->assertCreated();

    return SafetyFlag::query()->latest('id')->first();
}

function sfhAcknowledge($test, User $admin, SafetyFlag $flag): void
{
    $test->actingAs($admin)->postJson(route('admin.safety_flags.acknowledge', ['flag' => $flag->public_id]))->assertOk();
}

beforeEach(function () {
    config(['safety.enabled' => true, 'safety.fallback_mailbox' => null]);
    Event::fake([SafetyFlagsChangedEvent::class]);
    Bus::fake([ReAlertSafetyFlagJob::class]);
});

test('the handler adds notes and actions (kept apart from the history, encrypted); nobody else can, and not before it is handled', function () {
    ['counsellor' => $counsellor, 'therapy' => $therapy] = sfhTherapy();
    $flag = sfhRaise($this, $counsellor, $therapy);
    $handler = sfhAdmin();
    $other = sfhAdmin();

    $this->actingAs($handler)->postJson(route('admin.safety_flags.note', ['flag' => $flag->public_id]), ['content' => 'Called the counsellor.'])->assertForbidden();
    sfhAcknowledge($this, $handler, $flag);

    $this->actingAs($handler)->postJson(route('admin.safety_flags.note', ['flag' => $flag->public_id]), ['content' => 'Called the counsellor; plan agreed.'])->assertOk();
    $this->actingAs($handler)->postJson(route('admin.safety_flags.action', ['flag' => $flag->public_id]), ['action' => 'OTHER'])->assertStatus(422);
    $this->actingAs($handler)->postJson(route('admin.safety_flags.action', ['flag' => $flag->public_id]), ['action' => 'CONTACTED_EMERGENCY_SERVICES', 'content' => 'Ambulance 14:05'])->assertOk();
    $this->actingAs($other)->postJson(route('admin.safety_flags.note', ['flag' => $flag->public_id]), ['content' => 'Me too'])->assertForbidden();

    expect(SafetyFlagNote::query()->count())->toBe(2)
        ->and(DB::table('safety_flag_notes')->pluck('content')->implode(' '))->not->toContain('plan agreed')
        ->and($flag->events()->whereIn('action', [SafetyFlagEvent::NOTE, SafetyFlagEvent::ACTION_TAKEN])->get()->map->data->all())
        ->toBe([['noteId' => SafetyFlagNote::query()->orderBy('id')->first()->id], ['noteId' => SafetyFlagNote::query()->orderByDesc('id')->first()->id, 'actionType' => 'CONTACTED_EMERGENCY_SERVICES']]);

    $why = $this->actingAs($handler)->postJson(route('admin.safety_flags.reason', ['flag' => $flag->public_id]))->assertOk();
    expect($why->json('notes.0.content'))->toBe('Called the counsellor; plan agreed.')->and($why->json('notes.1.action'))->toBe('Contacted emergency services');

    // Everyone else who sees it gets what happened and when -- never what was written, nor the counsellor's name.
    $list = $this->actingAs($other)->getJson(route('admin.safety_flags.list'))->assertOk();
    expect(collect($list->json('flags.0.history'))->pluck('label')->all())->toBe(['Raised by the counsellor', 'Acknowledged', 'Note added', 'Action recorded'])
        ->and($list->getContent())->not->toContain('plan agreed')->not->toContain('Ambulance')->not->toContain((string) $counsellor->getName());
});

test('the handler resolves it with an outcome and a note; that frees the person for another flag; the counsellor sees only "resolved"', function () {
    ['counsellor' => $counsellor, 'therapy' => $therapy] = sfhTherapy();
    $flag = sfhRaise($this, $counsellor, $therapy);
    $handler = sfhAdmin();
    sfhAcknowledge($this, $handler, $flag);

    $this->actingAs(sfhAdmin())->postJson(route('admin.safety_flags.resolve', ['flag' => $flag->public_id]), ['outcome' => 'SAFETY_PLAN', 'note' => 'x'])->assertForbidden();
    $this->actingAs($handler)->postJson(route('admin.safety_flags.resolve', ['flag' => $flag->public_id]), ['outcome' => 'SOMETHING'])->assertStatus(422)->assertJsonValidationErrors(['outcome', 'note']);
    $this->actingAs($handler)->postJson(route('admin.safety_flags.resolve', ['flag' => $flag->public_id]), ['outcome' => 'SAFETY_PLAN', 'note' => 'Safety plan with the counsellor and family.'])->assertOk();
    $this->actingAs($handler)->postJson(route('admin.safety_flags.resolve', ['flag' => $flag->public_id]), ['outcome' => 'SAFETY_PLAN', 'note' => 'Again'])->assertStatus(409);

    $flag->refresh();
    expect($flag->status)->toBe(SafetyFlagStatusEnum::resolved)->and($flag->outcome_note)->toBe('Safety plan with the counsellor and family.')
        ->and(DB::table('safety_flags')->value('outcome_note'))->not->toContain('family')
        ->and($flag->open_client_key)->toBeNull();

    $statuses = $this->actingAs($counsellor->user)->getJson(route('api.safety_flags.therapy_statuses', ['therapyId' => $therapy->id]))->assertOk();
    expect($statuses->json('flags.0.statusLabel'))->toBe('Resolved')->and($statuses->json('flags.0.canWithdraw'))->toBeFalse()
        ->and($statuses->getContent())->not->toContain('Safety plan')->not->toContain('stopped eating')->not->toContain($handler->name);

    sfhRaise($this, $counsellor, $therapy);
    expect(SafetyFlag::query()->count())->toBe(2);
});

test('the counsellor who raised it can withdraw it, with a reason; the handler is told (no content) and can still note and resolve it', function () {
    Notification::fake();
    ['counsellor' => $counsellor, 'therapy' => $therapy] = sfhTherapy();
    $flag = sfhRaise($this, $counsellor, $therapy);
    $handler = sfhAdmin();
    sfhAcknowledge($this, $handler, $flag);

    $stranger = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $this->actingAs($stranger->user)->postJson(route('api.safety_flags.withdraw', ['flag' => $flag->public_id]), ['reason' => 'Not mine to withdraw'])->assertNotFound();
    $this->actingAs($counsellor->user)->postJson(route('api.safety_flags.withdraw', ['flag' => $flag->public_id]), ['reason' => ''])->assertStatus(422);
    $this->actingAs($counsellor->user)->postJson(route('api.safety_flags.withdraw', ['flag' => $flag->public_id]), ['reason' => 'We talked it through; much calmer.'])->assertOk();
    $this->actingAs($counsellor->user)->postJson(route('api.safety_flags.withdraw', ['flag' => $flag->public_id]), ['reason' => 'Again please'])->assertStatus(409);

    $flag->refresh();
    expect($flag->status)->toBe(SafetyFlagStatusEnum::withdrawn)->and($flag->open_client_key)->toBeNull()->and($flag->withdrawn_reason)->toBe('We talked it through; much calmer.');
    Notification::assertSentTo($handler, SafetyFlagWithdrawnNotification::class, fn ($notification) => ! str_contains(json_encode($notification->toMail($handler)->introLines), 'calmer'));

    $this->actingAs($handler)->postJson(route('admin.safety_flags.note', ['flag' => $flag->public_id]), ['content' => 'Checked with the counsellor.'])->assertOk();
    expect($this->actingAs($handler)->postJson(route('admin.safety_flags.reason', ['flag' => $flag->public_id]))->json('withdrawnReason'))->toBe('We talked it through; much calmer.');
    $this->actingAs($handler)->postJson(route('admin.safety_flags.resolve', ['flag' => $flag->public_id]), ['outcome' => 'NOT_WARRANTED', 'note' => 'Withdrawn; nothing more needed.'])->assertOk();
});

test('withdrawn before anyone acknowledged it: the administrators who were alerted are told, and it is never re-alerted', function () {
    Notification::fake();
    ['counsellor' => $counsellor, 'therapy' => $therapy] = sfhTherapy();
    $admin = sfhAdmin();
    $super = sfhAdmin(super: true);
    $flag = sfhRaise($this, $counsellor, $therapy, 'IMMINENT');

    $this->actingAs($counsellor->user)->postJson(route('api.safety_flags.withdraw', ['flag' => $flag->public_id]), ['reason' => 'Raised by mistake.'])->assertOk();
    Notification::assertSentTo([$admin, $super], SafetyFlagWithdrawnNotification::class);

    $this->travel(2)->hours();
    expect(ReAlertSafetyFlagsAction::new()->execute())->toBe(0);
    Notification::assertNotSentTo($super, SafetyFlagReAlertNotification::class);
});

test('nobody acknowledged it in time: the super administrators who aren\'t involved are alerted again, once -- by urgency', function () {
    Notification::fake();
    ['client' => $client, 'counsellor' => $counsellor, 'therapy' => $therapy] = sfhTherapy();
    $normal = sfhAdmin();
    $super = sfhAdmin(super: true);
    $involvedSuper = sfhAdmin(super: true);
    Guardianship::query()->create(['guardian_id' => $involvedSuper->id, 'ward_id' => $client->id, 'ward_was_minor_at_creation' => false]);
    $imminent = sfhRaise($this, $counsellor, $therapy, 'IMMINENT');
    Bus::assertDispatched(ReAlertSafetyFlagJob::class, fn ($job) => $job->safetyFlagId === $imminent->id);

    ['counsellor' => $otherCounsellor, 'therapy' => $other] = sfhTherapy();
    $urgent = sfhRaise($this, $otherCounsellor, $other, 'URGENT');
    sfhAcknowledge($this, $normal, $urgent);

    $this->travel(59)->minutes();
    expect(ReAlertSafetyFlagsAction::new()->execute())->toBe(0);
    $this->travel(2)->minutes();
    expect(ReAlertSafetyFlagsAction::new()->execute($imminent->id))->toBe(1)
        ->and(ReAlertSafetyFlagsAction::new()->execute())->toBe(0);

    Notification::assertSentTo($super, SafetyFlagReAlertNotification::class, fn ($notification) => $notification->toArray($super)['safetyFlag'] === $imminent->public_id
        && ! str_contains(json_encode($notification->toMail($super)->introLines), 'Ama'));
    Notification::assertNotSentTo([$normal, $involvedSuper], SafetyFlagReAlertNotification::class);
    expect($imminent->fresh()->events()->pluck('action')->all())->toContain(SafetyFlagEvent::REALERTED)
        ->and($urgent->fresh()->realerted_at)->toBeNull();

    // A broken setting never means "never": the default is used.
    config(['safety.realert_minutes.URGENT' => 'soon']);
    expect(SafetyFlag::realertMinutes(SafetyFlagUrgencyEnum::urgent))->toBe(240);
});

test('with nobody to tell, the fallback mailbox is emailed and the history says so', function () {
    Notification::fake();
    config(['safety.fallback_mailbox' => 'safety-team@example.com']);
    ['counsellor' => $counsellor, 'therapy' => $therapy] = sfhTherapy();

    $flag = sfhRaise($this, $counsellor, $therapy, 'IMMINENT');

    Notification::assertSentOnDemand(SafetyFlagRaisedNotification::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'safety-team@example.com' && $channels === ['mail']);
    expect($flag->events()->pluck('action')->all())->toContain(SafetyFlagEvent::FALLBACK_ALERTED);

    $this->travel(61)->minutes();
    ReAlertSafetyFlagsAction::new()->execute();
    Notification::assertSentOnDemand(SafetyFlagReAlertNotification::class);
});

test('a group\'s counsellors see its flags\' status -- a co-counsellor too, never the reason -- and only the one who raised it can withdraw it; the client sees nothing', function () {
    $member = User::factory()->adult()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $coCounsellor = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);
    $group = GroupTherapy::factory()->create(['addedby_type' => Counsellor::class, 'addedby_id' => $counsellor->id, 'status' => TherapyStatusEnum::in_session->value, 'anonymous' => false]);
    $group->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $group->counsellors()->attach($coCounsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);
    $group->users()->attach($member->id, ['anonymous' => true, 'was_minor_at_join' => false]);
    $key = (string) $group->users()->whereKey($member->id)->first()->pivot->id;
    $this->actingAs($counsellor->user)->postJson(route('api.safety_flags.group', ['groupTherapyId' => $group->id]), [
        'urgency' => 'URGENT', 'reason' => SFH_REASON, 'discussedWithClient' => 'NO', 'emergencyServicesContacted' => false, 'memberKey' => $key,
    ])->assertCreated();

    $mine = $this->actingAs($counsellor->user)->getJson(route('api.safety_flags.group_statuses', ['groupTherapyId' => $group->id]))->assertOk();
    $theirs = $this->actingAs($coCounsellor->user)->getJson(route('api.safety_flags.group_statuses', ['groupTherapyId' => $group->id]))->assertOk();
    expect($mine->json('flags.0.canWithdraw'))->toBeTrue()->and($mine->json('flags.0.about'))->toBe(ConstantsEnum::anonymousUserLabel->value)
        ->and($theirs->json('flags.0.canWithdraw'))->toBeFalse()->and($theirs->json('flags.0.statusLabel'))->toBe('Sent -- waiting for an administrator')
        ->and($theirs->getContent())->not->toContain('stopped eating');
    $this->actingAs($coCounsellor->user)->postJson(route('api.safety_flags.withdraw', ['flag' => $mine->json('flags.0.id')]), ['reason' => 'Not my flag'])->assertNotFound();
    $this->actingAs($member)->getJson(route('api.safety_flags.group_statuses', ['groupTherapyId' => $group->id]))->assertNotFound();
});

test('the demo data adds a withdrawn and a resolved flag', function () {
    (new DatabaseSeeder)->createSafetyFlagDemoData(sfhAdmin(super: true));

    expect(SafetyFlag::query()->orderBy('id')->pluck('status')->map->value->all())->toBe(['OPEN', 'ACKNOWLEDGED', 'WITHDRAWN', 'RESOLVED'])
        ->and(SafetyFlag::query()->whereNotNull('open_client_key')->count())->toBe(2);
});

test('review fixes: no fallback is recorded unless it went out; the re-alert does nothing while off; the history says so when it does', function () {
    Notification::fake();
    ['counsellor' => $counsellor, 'therapy' => $therapy] = sfhTherapy();
    $flag = sfhRaise($this, $counsellor, $therapy, 'IMMINENT');
    // Nobody to tell and no mailbox: logged, but no "fallback" in the history.
    expect($flag->events()->pluck('action')->all())->not->toContain(SafetyFlagEvent::FALLBACK_ALERTED);

    $this->travel(61)->minutes();
    config(['safety.enabled' => false]);
    expect(ReAlertSafetyFlagsAction::new()->execute())->toBe(0)->and($flag->fresh()->realerted_at)->toBeNull();

    config(['safety.enabled' => true, 'safety.fallback_mailbox' => 'not-an-address']);
    expect(SafetyFlag::fallbackMailbox())->toBeNull();
    config(['safety.fallback_mailbox' => 'safety-team@example.com']);
    expect(ReAlertSafetyFlagsAction::new()->execute())->toBe(1)
        ->and($flag->fresh()->events()->pluck('action')->all())->toContain(SafetyFlagEvent::FALLBACK_ALERTED)->not->toContain(SafetyFlagEvent::REALERTED);
});

test('review fixes: a flag withdrawn after it was taken on stays in the default list and can be handed on; one withdrawn before can\'t be resolved', function () {
    Notification::fake();
    ['counsellor' => $counsellor, 'therapy' => $therapy] = sfhTherapy();
    $flag = sfhRaise($this, $counsellor, $therapy);
    $handler = sfhAdmin();
    $super = sfhAdmin(super: true);
    sfhAcknowledge($this, $handler, $flag);
    $this->actingAs($counsellor->user)->postJson(route('api.safety_flags.withdraw', ['flag' => $flag->public_id]), ['reason' => 'Calmer now, talked it through.'])->assertOk();

    expect(collect($this->actingAs($handler)->getJson(route('admin.safety_flags.list'))->json('flags'))->pluck('id'))->toContain($flag->public_id)
        ->and(DB::table('safety_flags')->value('withdrawn_reason'))->not->toContain('Calmer');
    $next = sfhAdmin();
    $this->actingAs($super)->postJson(route('admin.safety_flags.reassign', ['flag' => $flag->public_id]), ['adminId' => $next->id])->assertOk();
    $this->actingAs($next)->postJson(route('admin.safety_flags.resolve', ['flag' => $flag->public_id]), ['outcome' => 'NOT_WARRANTED', 'note' => 'Withdrawn; checked.'])->assertOk();
    $this->actingAs($next)->postJson(route('admin.safety_flags.note', ['flag' => $flag->public_id]), ['content' => 'After resolving'])->assertStatus(409);

    // Withdrawn before anyone took it on: nobody handles it, nothing to resolve.
    ['counsellor' => $other, 'therapy' => $otherTherapy] = sfhTherapy();
    $early = sfhRaise($this, $other, $otherTherapy);
    $this->actingAs($other->user)->postJson(route('api.safety_flags.withdraw', ['flag' => $early->public_id]), ['reason' => 'Raised by mistake.'])->assertOk();
    $this->actingAs($super)->postJson(route('admin.safety_flags.resolve', ['flag' => $early->public_id]), ['outcome' => 'OTHER', 'note' => 'x'])->assertForbidden();
    expect(collect($this->actingAs($super)->getJson(route('admin.safety_flags.list'))->json('flags'))->pluck('id'))->not->toContain($early->public_id);
});

test('review fixes: if the handler can\'t act on it any more, a withdrawal goes to the super administrators, to hand it on', function () {
    Notification::fake();
    ['client' => $client, 'counsellor' => $counsellor, 'therapy' => $therapy] = sfhTherapy();
    $flag = sfhRaise($this, $counsellor, $therapy);
    $handler = sfhAdmin();
    $super = sfhAdmin(super: true);
    sfhAcknowledge($this, $handler, $flag);
    Guardianship::query()->create(['guardian_id' => $handler->id, 'ward_id' => $client->id, 'ward_was_minor_at_creation' => false]);

    $this->actingAs($counsellor->user)->postJson(route('api.safety_flags.withdraw', ['flag' => $flag->public_id]), ['reason' => 'No longer needed.'])->assertOk();

    Notification::assertNotSentTo($handler, SafetyFlagWithdrawnNotification::class);
    Notification::assertSentTo($super, SafetyFlagWithdrawnNotification::class, fn ($notification) => str_contains(json_encode($notification->toMail($super)->introLines), 'hand it to someone'));
});

test('review fixes: the statuses of a 1:1 and a help chat are for their counsellor only, and keep the person anonymous', function () {
    ['client' => $client, 'counsellor' => $counsellor, 'therapy' => $therapy] = sfhTherapy();
    $therapy->forceFill(['anonymous' => true])->save();
    sfhRaise($this, $counsellor, $therapy);
    $stranger = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id]);

    expect($this->actingAs($counsellor->user)->getJson(route('api.safety_flags.therapy_statuses', ['therapyId' => $therapy->id]))->json('flags.0.about'))->not->toContain('Ama');
    foreach ([$client, $stranger->user] as $user) {
        $this->actingAs($user)->getJson(route('api.safety_flags.therapy_statuses', ['therapyId' => $therapy->id]))->assertNotFound();
    }

    $person = User::factory()->adult()->create(['firstName' => 'Kofi']);
    $helper = Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id, 'urgent_help_available_until' => now()->addHours(2)]);
    Event::fake([SafetyFlagsChangedEvent::class, HelpRequestRingingEvent::class, HelpRequestUpdatedEvent::class, HelpQueueChangedEvent::class]);
    Bus::fake([ReAlertSafetyFlagJob::class, EscalateHelpRequestJob::class]);
    $id = $this->actingAs($person)->postJson(route('help.requests.store'), ['anonymous' => true])->json('helpRequest.id');
    $this->actingAs($helper->user)->postJson(route('help.requests.accept', ['helpRequest' => $id]))->assertOk();
    $this->actingAs($helper->user)->postJson(route('help.requests.safety_flag', ['helpRequest' => $id]), [
        'urgency' => 'IMMINENT', 'reason' => SFH_REASON, 'discussedWithClient' => 'NOT_POSSIBLE', 'emergencyServicesContacted' => true,
    ])->assertCreated();

    expect($this->actingAs($helper->user)->getJson(route('help.requests.safety_flags', ['helpRequest' => $id]))->json('flags.0.about'))->toBe('Anonymous');
    $this->actingAs($stranger->user)->getJson(route('help.requests.safety_flags', ['helpRequest' => $id]))->assertNotFound();
    $this->actingAs($person)->getJson(route('help.requests.safety_flags', ['helpRequest' => $id]))->assertNotFound();
});

test('a note is never deleted, and only its content can be cleared -- by the database', function () {
    ['counsellor' => $counsellor, 'therapy' => $therapy] = sfhTherapy();
    $flag = sfhRaise($this, $counsellor, $therapy);
    $handler = sfhAdmin();
    sfhAcknowledge($this, $handler, $flag);
    $this->actingAs($handler)->postJson(route('admin.safety_flags.note', ['flag' => $flag->public_id]), ['content' => 'Spoke to the family.'])->assertOk()
        ->assertJsonPath('note.content', 'Spoke to the family.');

    expect(fn () => DB::table('safety_flag_notes')->delete())->toThrow(Exception::class)
        ->and(fn () => DB::table('safety_flag_notes')->update(['content' => 'changed']))->toThrow(Exception::class)
        ->and(fn () => DB::table('safety_flag_notes')->update(['kind' => 'ACTION']))->toThrow(Exception::class);
    DB::table('safety_flag_notes')->update(['content' => null]);
    expect(DB::table('safety_flag_notes')->value('content'))->toBeNull();
})->group('mysql-triggers');
