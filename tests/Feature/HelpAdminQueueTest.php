<?php

use App\Actions\Help\EscalateHelpRequestsAction;
use App\Enums\AdministratorTypeEnum;
use App\Enums\HelpRequestStatusEnum;
use App\Events\HelpQueueChangedEvent;
use App\Events\HelpRequestRingingEvent;
use App\Events\HelpRequestUpdatedEvent;
use App\Jobs\EscalateHelpRequestJob;
use App\Models\AdminContentAccess;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\Guardianship;
use App\Models\HelpRequest;
use App\Models\User;
use App\Notifications\HelpRequestWaitingNotification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

// SCRUM-33 (TT-4.4b): the administrators' urgent help queue -- alerts when someone waits, ringing again
// or ringing one counsellor, closing, who asked (logged), and the involved rule. Helpers are file-local
// and haq-prefixed (--parallel).

function haqAdmin(bool $super = false): User
{
    return User::factory()->adult()->has(Administrator::factory()->state([
        'type' => $super ? AdministratorTypeEnum::super->value : AdministratorTypeEnum::normal->value,
    ]))->create();
}

function haqCounsellor(bool $onCall = true): Counsellor
{
    return Counsellor::factory()->create(['user_id' => User::factory()->adult()->create()->id, 'urgent_help_available_until' => $onCall ? now()->addHours(2) : null]);
}

function haqAsk($test, ?User $person = null, array $input = []): HelpRequest
{
    $person ??= User::factory()->adult()->create();

    return HelpRequest::query()->find($test->actingAs($person)->postJson(route('help.requests.store'), $input)->assertOk()->json('helpRequest.id'));
}

beforeEach(function () {
    Event::fake([HelpRequestRingingEvent::class, HelpRequestUpdatedEvent::class, HelpQueueChangedEvent::class]);
    Bus::fake([EscalateHelpRequestJob::class]);
});

test('asking schedules the two alerts and tells the queue', function () {
    $help = haqAsk($this);

    Bus::assertDispatchedTimes(EscalateHelpRequestJob::class, 2);
    Event::assertDispatched(HelpQueueChangedEvent::class, fn ($event) => $event->broadcastWith() === ['helpRequestId' => $help->id]);
});

test('a request still waiting alerts administrators once, then again -- never the ones involved, never with who asked', function () {
    Notification::fake();
    $person = User::factory()->adult()->create(['firstName' => 'Hidden']);
    $guardianAdmin = haqAdmin();
    Guardianship::query()->create(['guardian_id' => $guardianAdmin->id, 'ward_id' => $person->id]);
    $admin = haqAdmin();
    $help = haqAsk($this, $person, ['note' => 'Private note']);

    expect(EscalateHelpRequestsAction::new()->execute())->toBe(0);

    $this->travel(config('help.first_alert_seconds') + 1)->seconds();
    expect(EscalateHelpRequestsAction::new()->execute())->toBe(1)
        ->and(EscalateHelpRequestsAction::new()->execute($help->id))->toBe(0);

    $this->travel(config('help.second_alert_seconds'))->seconds();
    expect(EscalateHelpRequestsAction::new()->execute())->toBe(1);

    Notification::assertSentToTimes($admin, HelpRequestWaitingNotification::class, 2);
    Notification::assertNotSentTo($guardianAdmin, HelpRequestWaitingNotification::class);
    Notification::assertSentTo($admin, HelpRequestWaitingNotification::class, function ($notification) use ($admin) {
        $mail = implode(' ', $notification->toMail($admin)->introLines);

        return ! str_contains($mail, 'Hidden') && ! str_contains($mail, 'Private note');
    });
});

test('an answered request isn\'t alerted', function () {
    Notification::fake();
    haqAdmin();
    $help = haqAsk($this);
    $this->actingAs(haqCounsellor()->user)->postJson(route('help.requests.accept', ['helpRequest' => $help->id]))->assertOk();

    $this->travel(config('help.second_alert_seconds') + 1)->seconds();
    expect(EscalateHelpRequestsAction::new()->execute())->toBe(0);
});

test('an administrator rings one counsellor, who may accept though not on call; others still can\'t', function () {
    $admin = haqAdmin();
    $help = haqAsk($this);
    $chosen = haqCounsellor(onCall: false);
    $notChosen = haqCounsellor(onCall: false);

    $this->actingAs($admin)->postJson(route('admin.help_requests.ring_counsellor', ['helpRequest' => $help->id]), ['counsellorId' => $chosen->id])->assertOk();
    Event::assertDispatched(HelpRequestRingingEvent::class, fn ($event) => collect($event->broadcastOn())->pluck('name')->all() === ["private-counsellors.{$chosen->id}"]);

    $this->actingAs($chosen->user)->getJson(route('help.requests.show', ['helpRequest' => $help->id]))->assertOk();
    $this->actingAs($notChosen->user)->postJson(route('help.requests.accept', ['helpRequest' => $help->id]))->assertStatus(409);
    $this->actingAs($chosen->user)->postJson(route('help.requests.accept', ['helpRequest' => $help->id]))->assertOk();
    expect($help->fresh()->counsellor_id)->toBe($chosen->id);
});

test('a counsellor who can\'t take it can\'t be rung directly: unverified, the person\'s guardian, or in another chat', function () {
    $admin = haqAdmin();
    $person = User::factory()->adult()->create();
    $help = haqAsk($this, $person);
    $guardian = haqCounsellor();
    Guardianship::query()->create(['guardian_id' => $guardian->user_id, 'ward_id' => $person->id]);
    $unverified = Counsellor::factory()->unverified()->create(['user_id' => User::factory()->adult()->create()->id]);

    foreach ([$guardian, $unverified] as $counsellor) {
        $this->actingAs($admin)->postJson(route('admin.help_requests.ring_counsellor', ['helpRequest' => $help->id]), ['counsellorId' => $counsellor->id])->assertStatus(422);
    }
    expect(collect($this->actingAs($admin)->getJson(route('admin.help_requests.counsellors', ['helpRequest' => $help->id]))->json('counsellors'))->pluck('id'))
        ->not->toContain($guardian->id)->not->toContain($unverified->id);
});

test('an administrator closes a waiting request; the person can ask again', function () {
    $admin = haqAdmin();
    $person = User::factory()->adult()->create();
    $help = haqAsk($this, $person);

    $this->actingAs($admin)->postJson(route('admin.help_requests.close', ['helpRequest' => $help->id]), ['note' => 'Reached by phone'])->assertOk();

    expect($help->fresh())->status->toBe(HelpRequestStatusEnum::closed)->closed_note->toBe('Reached by phone')->closed_by_id->toBe($admin->id)
        ->and(haqAsk($this, $person)->id)->not->toBe($help->id);
    $this->actingAs($admin)->postJson(route('admin.help_requests.close', ['helpRequest' => $help->id]))->assertStatus(409);
});

test('who asked is shown only when asked for, and every look is recorded; an involved administrator sees and does nothing', function () {
    $admin = haqAdmin();
    $person = User::factory()->adult()->create(['firstName' => 'Secret', 'username' => 'secret_person']);
    $help = haqAsk($this, $person, ['anonymous' => true]);

    $queue = $this->actingAs($admin)->get(route('administrator.help_requests'))->assertOk()->viewData('page')['props']['requests'];
    expect($queue[0]['name'])->toBe('Anonymous')->and(json_encode($queue))->not->toContain('Secret');

    expect($this->actingAs($admin)->postJson(route('admin.help_requests.reveal', ['helpRequest' => $help->id]))->assertOk()->json('username'))->toBe('secret_person')
        ->and(AdminContentAccess::query()->where('admin_user_id', $admin->id)->where('resource', AdminContentAccess::HELP_REQUEST_IDENTITY)->count())->toBe(1);

    $guardianAdmin = haqAdmin();
    Guardianship::query()->create(['guardian_id' => $guardianAdmin->id, 'ward_id' => $person->id]);
    // Not even "you're involved": a guardian mustn't learn their ward asked (nor from the badge).
    expect($this->actingAs($guardianAdmin)->get(route('administrator.help_requests'))->viewData('page')['props']['requests'])->toBe([])
        ->and($this->actingAs($guardianAdmin)->getJson(route('admin.badges'))->json('badges.helpRequests'))->toBe(0);
    foreach (['admin.help_requests.reveal', 'admin.help_requests.ring', 'admin.help_requests.close'] as $name) {
        $this->actingAs($guardianAdmin)->postJson(route($name, ['helpRequest' => $help->id]))->assertStatus(403);
    }
    $this->actingAs(User::factory()->adult()->create())->postJson(route('admin.help_requests.reveal', ['helpRequest' => $help->id]))->assertStatus(403);
});

test('the badge counts who is waiting, live; only administrators get the queue', function () {
    $admin = haqAdmin();
    haqAsk($this);
    haqAsk($this);

    expect($this->actingAs($admin)->getJson(route('admin.badges'))->json('badges.helpRequests'))->toBe(2);
    $this->actingAs(User::factory()->adult()->create())->get(route('administrator.help_requests'))->assertRedirect();
});

test('an administrator who is a counsellor on call is involved in what waits: no look at who asked, then answering', function () {
    $adminCounsellor = haqAdmin();
    Counsellor::factory()->create(['user_id' => $adminCounsellor->id, 'urgent_help_available_until' => now()->addHour()]);
    $help = haqAsk($this, null, ['anonymous' => true]);

    $this->actingAs($adminCounsellor)->postJson(route('admin.help_requests.reveal', ['helpRequest' => $help->id]))->assertStatus(403);
    expect($this->actingAs($adminCounsellor)->get(route('administrator.help_requests'))->viewData('page')['props']['requests'])->toBe([]);
});

test('an administrator can\'t ring their own counsellor profile, and one who looked at who asked can\'t answer it', function () {
    $admin = haqAdmin();
    $own = Counsellor::factory()->create(['user_id' => $admin->id]);
    $help = haqAsk($this);

    $this->actingAs($admin)->postJson(route('admin.help_requests.ring_counsellor', ['helpRequest' => $help->id]), ['counsellorId' => $own->id])->assertStatus(422);
    expect(collect($this->actingAs($admin)->getJson(route('admin.help_requests.counsellors', ['helpRequest' => $help->id]))->json('counsellors'))->pluck('id'))->not->toContain($own->id);

    // Looked first (off call), then went on call: still can't answer.
    $this->actingAs($admin)->postJson(route('admin.help_requests.reveal', ['helpRequest' => $help->id]))->assertOk();
    $own->forceFill(['urgent_help_available_until' => now()->addHour()])->save();
    $this->actingAs($admin->fresh())->postJson(route('help.requests.accept', ['helpRequest' => $help->id]))->assertStatus(409);
});

test('a counsellor rung directly has their ring closed when the person stops waiting, it expires, or another is rung instead', function () {
    $admin = haqAdmin();
    $first = haqCounsellor(onCall: false);
    $second = haqCounsellor(onCall: false);
    $person = User::factory()->adult()->create();
    $help = haqAsk($this, $person);

    $this->actingAs($admin)->postJson(route('admin.help_requests.ring_counsellor', ['helpRequest' => $help->id]), ['counsellorId' => $first->id])->assertOk();
    $this->actingAs($admin)->postJson(route('admin.help_requests.ring_counsellor', ['helpRequest' => $help->id]), ['counsellorId' => $second->id])->assertOk();
    Event::assertDispatched(HelpRequestUpdatedEvent::class, fn ($event) => in_array("private-counsellors.{$first->id}", collect($event->broadcastOn())->pluck('name')->all(), true));

    $this->actingAs($person)->postJson(route('help.requests.cancel', ['helpRequest' => $help->id]))->assertOk();
    Event::assertDispatched(HelpRequestUpdatedEvent::class, fn ($event) => $event->broadcastWith()['status'] === 'CANCELLED'
        && in_array("private-counsellors.{$second->id}", collect($event->broadcastOn())->pluck('name')->all(), true));
});

test('ringing again works, at most once every 15 seconds a request', function () {
    $admin = haqAdmin();
    haqCounsellor();
    $help = haqAsk($this);

    expect($this->actingAs($admin)->postJson(route('admin.help_requests.ring', ['helpRequest' => $help->id]))->assertOk()->json('rung'))->toBe(1);
    $this->actingAs(haqAdmin())->postJson(route('admin.help_requests.ring', ['helpRequest' => $help->id]))->assertStatus(429);
});

test('who asked can\'t be looked up once a request is over a day old', function () {
    $admin = haqAdmin();
    $person = User::factory()->adult()->create();
    $help = haqAsk($this, $person);
    $this->actingAs($person)->postJson(route('help.requests.cancel', ['helpRequest' => $help->id]))->assertOk();

    $this->travel(2)->days();
    $this->actingAs($admin)->postJson(route('admin.help_requests.reveal', ['helpRequest' => $help->id]))->assertStatus(403);
});

test('a counsellor with no counsellor name is listed, and shown in the queue, by their account name (QA)', function () {
    $admin = haqAdmin();
    $help = haqAsk($this);
    $unnamed = haqCounsellor(onCall: false);
    $unnamed->forceFill(['name' => null])->save();
    $accountName = $unnamed->user->name;

    $listed = collect($this->actingAs($admin)->getJson(route('admin.help_requests.counsellors', ['helpRequest' => $help->id]))->json('counsellors'))->firstWhere('id', $unnamed->id);
    expect($listed['name'])->toBe($accountName);

    $this->actingAs($admin)->postJson(route('admin.help_requests.ring_counsellor', ['helpRequest' => $help->id]), ['counsellorId' => $unnamed->id])->assertOk();
    expect(collect($this->actingAs($admin)->getJson(route('admin.help_requests.queue'))->json('requests'))->firstWhere('id', $help->id)['pulledCounsellor'])->toBe($accountName);
});
