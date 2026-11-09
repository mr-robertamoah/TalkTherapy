<?php

use App\Actions\Help\PruneHelpChatTranscriptsAction;
use App\Actions\Report\EnsureCanReportReportableAction;
use App\Actions\Report\TransitionReportStatusAction;
use App\Actions\Suspension\ApplySuspensionAction;
use App\Actions\Suspension\EnsureCanApplySuspensionAction;
use App\Enums\AdministratorTypeEnum;
use App\Enums\HelpRequestStatusEnum;
use App\Enums\ReportOutcomeEnum;
use App\Enums\ReportStatusEnum;
use App\Enums\SuspensionScopeEnum;
use App\Events\HelpRequestRingingEvent;
use App\Events\HelpRequestUpdatedEvent;
use App\Exceptions\SuspensionException;
use App\Models\AdminContentAccess;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\Guardianship;
use App\Models\HelpRequest;
use App\Models\Report;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\ReportClosedNotification;
use App\Notifications\ReportNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

// SCRUM-398: a counsellor reports an urgent help request, and the report leak it closes (a help chat's
// session or therapy reported through the generic endpoint showed admins who asked). Helpers are file-local
// and hrt-prefixed (--parallel).

function hrtOnCall(): Counsellor
{
    return Counsellor::factory()->for(User::factory()->adult()->state(['created_at' => now()->subDays(30), 'email_verified_at' => now()]))
        ->create(['urgent_help_available_until' => now()->addHours(2)]);
}

function hrtPerson(array $state = []): User
{
    return User::factory()->adult()->create(['created_at' => now()->subDays(30), 'email_verified_at' => now(), 'firstName' => 'Kwabena', 'lastName' => 'Secretname', ...$state]);
}

function hrtAsk($test, User $person, bool $anonymous = true): HelpRequest
{
    $id = $test->actingAs($person)->postJson(route('help.requests.store'), ['anonymous' => $anonymous, 'note' => 'please help'])->assertOk()->json('helpRequest.id');

    return HelpRequest::query()->find($id);
}

function hrtAdmin(AdministratorTypeEnum $type = AdministratorTypeEnum::super): User
{
    return User::factory()->adult()->has(Administrator::factory()->state(['type' => $type->value]))->create();
}

function hrtReport($test, User $counsellorUser, HelpRequest $help, string $reason = 'prank')
{
    return $test->actingAs($counsellorUser)->postJson(route('help.requests.report', ['helpRequest' => $help->id]), ['reason' => $reason, 'details' => 'Laughing and hung up']);
}

beforeEach(function () {
    Event::fake([HelpRequestRingingEvent::class, HelpRequestUpdatedEvent::class]);
    config(['help.new_account_requests_per_hour' => 3]);
});

test('a help chat\'s session or therapy can\'t be reported through the generic endpoint (the leak)', function () {
    $counsellor = hrtOnCall();
    $help = hrtAsk($this, hrtPerson());
    $this->actingAs($counsellor->user)->postJson(route('help.requests.accept', ['helpRequest' => $help->id]))->assertOk();
    $help->refresh();

    foreach ([['Session', $help->session_id], ['Therapy', $help->therapy_id]] as [$type, $id]) {
        $this->actingAs($counsellor->user)->postJson(route('api.reports.create'), [
            'description' => 'x', 'reportableType' => $type, 'reportableId' => $id, 'addedbyType' => 'counsellor', 'addedbyId' => $counsellor->id, 'data' => [],
        ])->assertStatus(422)->assertJsonPath('message', EnsureCanReportReportableAction::NOT_ALLOWED);
    }
    expect(Report::query()->count())->toBe(0);
});

test('reporting a session of an anonymous therapy never confirms a guessed client id', function () {
    $client = User::factory()->adult()->create();
    $counsellor = Counsellor::factory()->for(User::factory()->adult())->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id, 'anonymous' => true]);
    $session = Session::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id]);

    foreach ([$client->id, $client->id + 999] as $guess) {
        $this->actingAs($counsellor->user)->postJson(route('api.reports.create'), [
            'description' => 'x', 'reportableType' => 'Session', 'reportableId' => $session->id, 'addedbyType' => 'counsellor', 'addedbyId' => $counsellor->id, 'data' => ['userId' => $guess],
        ])->assertStatus(422);
    }
});

test('the counsellor who answered reports it; nothing changes for the person, and nobody is named in it', function () {
    $counsellor = hrtOnCall();
    $person = hrtPerson();
    $help = hrtAsk($this, $person);
    $this->actingAs($counsellor->user)->postJson(route('help.requests.accept', ['helpRequest' => $help->id]))->assertOk();
    Notification::fake();

    expect($this->actingAs($counsellor->user)->getJson(route('help.requests.show', ['helpRequest' => $help->id]))->json('helpRequest.canReport'))->toBeTrue();
    hrtReport($this, $counsellor->user, $help)->assertOk()->assertJson(['reported' => true]);

    $report = Report::query()->sole();
    expect($report->reportable_type)->toBe(HelpRequest::class)
        ->and($report->data ?? [])->toBe([])
        ->and($report->description)->toContain('Prank')
        ->and($report->reportedUser()->is($person))->toBeTrue()
        ->and($help->fresh()->status)->toBe(HelpRequestStatusEnum::connected);
    Notification::assertNotSentTo($person, ReportClosedNotification::class);

    // Once.
    hrtReport($this, $counsellor->user, $help)->assertStatus(422)->assertJsonPath('message', EnsureCanReportReportableAction::HELP_ALREADY_REPORTED);
    expect($this->actingAs($counsellor->user)->getJson(route('help.requests.show', ['helpRequest' => $help->id]))->json('helpRequest.canReport'))->toBeFalse();
});

test('a counsellor it is ringing may report it -- and it keeps ringing; nobody else may', function () {
    $ringing = hrtOnCall();
    $other = hrtOnCall();
    $person = hrtPerson();
    $help = hrtAsk($this, $person);
    $offDuty = Counsellor::factory()->for(User::factory()->adult())->create();

    hrtReport($this, $offDuty->user, $help)->assertStatus(403);
    hrtReport($this, $person, $help)->assertStatus(403);
    $this->actingAs($ringing->user)->postJson(route('help.requests.report', ['helpRequest' => 999999]), ['reason' => 'prank'])->assertStatus(403);
    hrtReport($this, $ringing->user, $help, 'abuse')->assertOk();

    expect($help->fresh()->status)->toBe(HelpRequestStatusEnum::waiting);
    // The other on-call counsellor can still answer it.
    $this->actingAs($other->user)->postJson(route('help.requests.accept', ['helpRequest' => $help->id]))->assertOk();
});

test('admins see who asked only through the logged reveal; an involved admin sees only that it exists', function () {
    $counsellor = hrtOnCall();
    $person = hrtPerson();
    $help = hrtAsk($this, $person);
    hrtReport($this, $counsellor->user, $help)->assertOk();
    $report = Report::query()->sole();

    $admin = hrtAdmin();
    $guardian = hrtAdmin();
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $person->id]);

    $page = $this->actingAs($admin)->get(route('administrator.reports'))->assertOk();
    $listed = collect($page->viewData('page')['props']['reports']['data'])->firstWhere('id', $report->id);
    expect(json_encode($listed))->not->toContain('Secretname')->not->toContain($person->username)->not->toContain('"id":'.$person->id.',')
        ->and($listed['reportedPerson'])->toBe(['type' => 'user', 'masked' => true, 'name' => 'Anonymous'])
        ->and($listed['helpRequest']['note'])->toBe('please help');

    $stub = collect($this->actingAs($guardian)->get(route('administrator.reports'))->viewData('page')['props']['reports']['data'])->firstWhere('id', $report->id);
    expect($stub)->toMatchArray(['involved' => true])->not->toHaveKey('helpRequest');
    $this->actingAs($guardian)->postJson(route('admin.reports.help_requester', ['report' => $report->id]))->assertStatus(403);

    // The reveal: who asked, recorded every time.
    $this->actingAs($admin)->postJson(route('admin.reports.help_requester', ['report' => $report->id]))
        ->assertOk()->assertJsonPath('person.username', $person->username);
    $this->actingAs($admin)->postJson(route('admin.reports.help_requester', ['report' => $report->id]))->assertOk();
    expect(AdminContentAccess::query()->where('admin_user_id', $admin->id)->where('resource', AdminContentAccess::HELP_REQUEST_IDENTITY)->where('subject_id', $help->id)->count())->toBe(2);
});

test('a report keeps the request\'s note and chat from being deleted while it is reviewed', function () {
    $counsellor = hrtOnCall();
    $help = hrtAsk($this, hrtPerson());
    hrtReport($this, $counsellor->user, $help)->assertOk();
    $help->forceFill(['status' => HelpRequestStatusEnum::expired->value, 'ended_at' => now()->subDays(400), 'active_user_id' => null])->save();
    config(['help.transcript_retention_days' => 365]);

    expect($help->fresh()->hasOpenReport())->toBeTrue();
    PruneHelpChatTranscriptsAction::new()->execute();

    expect($help->fresh()->note)->toBe('please help')->and($help->fresh()->transcript_deleted_at)->toBeNull();
});

test('suspending over a help report needs a logged reveal first; the person gets no report notice', function () {
    $counsellor = hrtOnCall();
    $person = hrtPerson();
    $help = hrtAsk($this, $person);
    hrtReport($this, $counsellor->user, $help)->assertOk();
    $report = Report::query()->sole();
    $admin = hrtAdmin();
    Notification::fake();

    TransitionReportStatusAction::new()->execute($admin, $report, ReportStatusEnum::under_review);
    TransitionReportStatusAction::new()->execute($admin, $report->fresh(), ReportStatusEnum::resolved, ReportOutcomeEnum::suspension_recommended, 'Repeated prank requests.');
    Notification::assertSentTo($counsellor->user, ReportClosedNotification::class);
    Notification::assertNotSentTo($person, ReportClosedNotification::class);

    expect(fn () => EnsureCanApplySuspensionAction::new()->execute($admin, $report->fresh(), SuspensionScopeEnum::account))
        ->toThrow(SuspensionException::class, EnsureCanApplySuspensionAction::REVEAL_FIRST);

    $this->actingAs($admin)->postJson(route('admin.reports.help_requester', ['report' => $report->id]))->assertOk();
    $suspension = ApplySuspensionAction::new()->execute($admin, $report->fresh(), SuspensionScopeEnum::account, 7, 'Repeated prank urgent help requests.');
    expect($suspension->user_id)->toBe($person->id);
});

test('the report email never goes to an involved admin', function () {
    $counsellor = hrtOnCall();
    $person = hrtPerson();
    $guardian = hrtAdmin(AdministratorTypeEnum::normal);
    Guardianship::query()->create(['guardian_id' => $guardian->id, 'ward_id' => $person->id]);
    $help = hrtAsk($this, $person);
    Notification::fake();

    hrtReport($this, $counsellor->user, $help)->assertOk();

    Notification::assertNotSentTo($guardian, ReportNotification::class);
});
