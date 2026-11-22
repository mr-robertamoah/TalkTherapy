<?php

use App\Actions\Recognition\GetCounsellorIdsWithOpenReportsAction;
use App\Actions\Recognition\GetRecognitionSuggestionsAction;
use App\Actions\Request\RespondToCounsellorRecognitionRequestAction;
use App\Actions\Suspension\ApplySuspensionAction;
use App\DTOs\CounsellorRecognitionDTO;
use App\Enums\AdministratorTypeEnum;
use App\Enums\ConstantsEnum;
use App\Enums\CounsellorRecognitionConsentEnum;
use App\Enums\CounsellorRecognitionDecisionEnum;
use App\Enums\HelpRequestStatusEnum;
use App\Enums\NotificationCategoryEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\StarSourceEnum;
use App\Enums\StarTypeEnum;
use App\Enums\SuspensionScopeEnum;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\CounsellorRecognition;
use App\Models\HelpRequest;
use App\Models\Report;
use App\Models\Request;
use App\Models\Star;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\CounsellorRecognisedNotification;
use App\Services\CounsellorRecognitionService;
use App\Support\NotificationCategories;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

// TT-7.11d1/SCRUM-473: the recognition backend -- suggestions from new-rule stars, a super administrator's decisions,
// and the counsellor's featuring consent.

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-20 12:00:00', 'UTC'));
    config(['recognition.per_month' => 5, 'recognition.stars_count_from' => null]);
    Notification::fake();
});

afterEach(function () {
    Carbon::setTestNow();
});

function rbkAdmin(bool $super = true): User
{
    return User::factory()->adult()
        ->has(Administrator::factory()->state([
            'type' => $super ? AdministratorTypeEnum::super->value : AdministratorTypeEnum::normal->value,
        ]))
        ->create();
}

function rbkCounsellor(array $state = []): Counsellor
{
    return Counsellor::factory()->create(array_merge(['user_id' => User::factory()->adult()], $state));
}

// $count new-rule stars, all at $at (so $at is also the first star). Written directly rather than through
// AwardCounsellorStarAction: these are fixtures for stars already earned, and the award action (SCRUM-471) refuses
// counsellors who are unverified or suspended *now* and applies the daily caps -- the eligibility tests need stars
// on counsellors whose verification lapsed or was revoked after they earned them.
function rbkStars(Counsellor $counsellor, int $count, string $at = '2026-10-10 10:00:00'): void
{
    static $key = 0;

    for ($i = 0; $i < $count; $i++) {
        $star = (new Star)->forceFill([
            'type' => StarSourceEnum::sessionHeld->starType()->value,
            'source' => StarSourceEnum::sessionHeld,
            'award_key' => 'session_held:rbk'.(++$key),
        ]);
        $star->starred()->associate($counsellor);
        $star->starreable()->associate($counsellor->user);
        $star->save();
        $star->forceFill(['created_at' => Carbon::parse($at, 'UTC')])->save();
    }
}

function rbkSuggest(string $month = '2026-10'): array
{
    return GetRecognitionSuggestionsAction::new()->execute(Carbon::parse("{$month}-01", 'UTC'));
}

function rbkIds(array $result): array
{
    return array_column($result['suggestions'], 'counsellorId');
}

function rbkReport(Model $reportable, Model $reporter, array $data, string $status = 'open'): Report
{
    $report = new Report(['description' => 'Something happened', 'data' => $data]);
    $report->addedby()->associate($reporter);
    $report->reportable()->associate($reportable);
    $report->save();
    $report->forceFill(['status' => $status])->save();

    return $report;
}

function rbkSuspend(Counsellor $counsellor): void
{
    $client = User::factory()->adult()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id]);
    $report = rbkReport($therapy, $client, ['counsellorId' => $counsellor->id], 'resolved');
    $report->forceFill(['outcome' => 'suspension_recommended', 'resolution_note' => 'Serious'])->save();

    ApplySuspensionAction::new()->execute(rbkAdmin(), $report, SuspensionScopeEnum::counsellor, 30, 'Repeated abusive messages to a client.');
}

function rbkDecide($test, string $action, Counsellor $counsellor, ?User $admin = null, string $month = '2026-10')
{
    return $test->actingAs($admin ?? rbkAdmin())->postJson(route("admin.recognition.{$action}"), [
        'month' => $month,
        'counsellor_id' => $counsellor->id,
    ]);
}

function rbkConsentRequest(CounsellorRecognition $recognition): ?Request
{
    return Request::query()->where('type', RequestTypeEnum::counsellorRecognition->value)
        ->where('for_type', CounsellorRecognition::class)->where('for_id', $recognition->id)->latest('id')->first();
}

function rbkEvents(CounsellorRecognition $recognition): array
{
    return $recognition->events()->get()->map(fn ($event) => $event->event->value)->all();
}

// ---- Suggestions ----

test('only counsellors eligible now are suggested', function () {
    config(['recognition.per_month' => 20]);
    $reporter = rbkCounsellor();

    $eligible = rbkCounsellor();
    $lapsed = rbkCounsellor(['verified_at' => now()->subYear(), 'verification_expires_at' => now()->subDay()]);
    $revoked = rbkCounsellor(['verification_revoked_at' => now()->subDay()]);
    $suspended = rbkCounsellor();
    $softDeleted = rbkCounsellor();
    $accountDeleted = rbkCounsellor();
    $reportedByData = rbkCounsellor();
    $reportedByHelp = rbkCounsellor();
    $ambiguous = rbkCounsellor();
    $resolved = rbkCounsellor();
    $dismissed = rbkCounsellor();

    foreach ([$eligible, $lapsed, $revoked, $suspended, $softDeleted, $accountDeleted, $reportedByData, $reportedByHelp, $ambiguous, $resolved, $dismissed] as $counsellor) {
        rbkStars($counsellor, 2);
    }

    rbkSuspend($suspended);
    $softDeleted->delete();
    $accountDeleted->user->delete();

    // Open, naming the counsellor in data.
    rbkReport($reportedByData->user, $reporter, ['counsellorId' => $reportedByData->id]);
    // Under review, about an urgent help request the counsellor's own account made.
    $help = HelpRequest::create(['user_id' => $reportedByHelp->user_id, 'status' => HelpRequestStatusEnum::ended->value]);
    rbkReport($help, $reporter, [], 'under_review');
    // Ambiguous (names two people, neither the reporter): counts against nobody.
    rbkReport($ambiguous->user, $reporter, ['counsellorId' => $ambiguous->id, 'userId' => User::factory()->create()->id]);
    // Closed reports never count.
    rbkReport($resolved->user, $reporter, ['counsellorId' => $resolved->id], 'resolved');
    rbkReport($dismissed->user, $reporter, ['counsellorId' => $dismissed->id], 'dismissed');

    $result = rbkSuggest();

    expect(rbkIds($result))->toEqualCanonicalizing([$eligible->id, $ambiguous->id, $resolved->id, $dismissed->id])
        ->and($result['unattributedOpenReports'])->toBe(1)
        ->and($result['unattributedOpenReportsNote'])->toBe(GetCounsellorIdsWithOpenReportsAction::UNATTRIBUTED_NOTE);

    expect(GetCounsellorIdsWithOpenReportsAction::new()->execute([$reportedByData->id, $reportedByHelp->id, $ambiguous->id, $resolved->id]))
        ->toBe(['counsellorIds' => [$reportedByData->id, $reportedByHelp->id], 'unattributed' => 1]);
});

test('the ranking is stars, then the earliest first star, then id, with shared ranks for ties', function () {
    $a = rbkCounsellor();
    $b = rbkCounsellor();
    $c = rbkCounsellor();
    $d = rbkCounsellor();
    rbkStars($a, 5, '2026-10-05 09:00:00');
    rbkStars($b, 5, '2026-10-03 09:00:00');
    rbkStars($d, 4, '2026-10-07 09:00:00');
    rbkStars($c, 4, '2026-10-07 09:00:00');

    $rows = rbkSuggest()['suggestions'];

    expect(array_column($rows, 'counsellorId'))->toBe([$b->id, $a->id, $c->id, $d->id])
        ->and(array_column($rows, 'rank'))->toBe([1, 2, 3, 3])
        ->and(array_column($rows, 'stars'))->toBe([5, 5, 4, 4])
        ->and($rows[0]['firstStarAt'])->toBe('2026-10-03T09:00:00Z')
        ->and(array_column($rows, 'tiedAtCutoff'))->toBe([false, false, false, false]);
});

test('everyone tied with the last place is suggested too', function () {
    config(['recognition.per_month' => 2]);
    $tied = collect(range(1, 3))->map(fn () => rbkCounsellor());
    $tied->each(fn (Counsellor $counsellor) => rbkStars($counsellor, 3));
    rbkStars(rbkCounsellor(), 2);

    $result = rbkSuggest();

    expect($result['suggestions'])->toHaveCount(3)
        ->and(rbkIds($result))->toBe($tied->pluck('id')->all())
        ->and(array_column($result['suggestions'], 'tiedAtCutoff'))->toBe([true, true, true]);

    // A tie on stars alone isn't a tie: the earlier first star is ahead.
    config(['recognition.per_month' => 1]);
    rbkStars($first = rbkCounsellor(), 4, '2026-10-01 00:00:00');
    rbkStars(rbkCounsellor(), 4, '2026-10-01 00:00:01');

    expect(rbkIds(rbkSuggest()))->toBe([$first->id]);
});

test('only new-rule stars inside the month count', function () {
    $counsellor = rbkCounsellor();
    $legacy = rbkCounsellor();

    rbkStars($counsellor, 1, '2026-10-01 00:00:00');
    rbkStars($counsellor, 5, '2026-11-01 00:00:00');
    rbkStars($counsellor, 5, '2026-09-30 23:59:59');

    // Legacy stars (no source), on the counsellor or their account, never count.
    foreach ([$legacy, $legacy->user] as $starred) {
        $star = Star::create(['type' => StarTypeEnum::contribution->value]);
        $star->starred()->associate($starred);
        $star->forceFill(['created_at' => Carbon::parse('2026-10-15', 'UTC')])->save();
    }

    $rows = rbkSuggest()['suggestions'];

    expect(array_column($rows, 'counsellorId'))->toBe([$counsellor->id])
        ->and($rows[0]['stars'])->toBe(1);
});

test('a decided row shows its decision, and one no longer suggested is listed with its flags', function () {
    config(['recognition.per_month' => 1]);
    $top = rbkCounsellor();
    $added = rbkCounsellor();
    rbkStars($top, 3);

    rbkDecide($this, 'approve', $top)->assertOk();
    config(['recognition.per_month' => 2]);
    rbkDecide($this, 'add', $added)->assertOk();
    rbkSuspend($added);

    $result = rbkSuggest();

    expect($result['approvedCount'])->toBe(2)
        ->and($result['suggestions'][0])->toMatchArray(['counsellorId' => $top->id, 'decision' => 'approved', 'consent' => 'pending', 'addedManually' => false])
        ->and($result['others'])->toHaveCount(1)
        ->and($result['others'][0])->toMatchArray(['counsellorId' => $added->id, 'decision' => 'approved', 'addedManually' => true, 'rank' => null])
        ->and($result['others'][0]['flags'])->toBe(['verificationState' => 'verified', 'suspended' => true, 'openReport' => false, 'deleted' => false]);
});

test('the suggestions route returns a month, defaulting to the previous one', function () {
    rbkStars($counsellor = rbkCounsellor(), 2);
    $admin = rbkAdmin();

    $this->actingAs($admin)->getJson(route('admin.recognition.suggestions'))
        ->assertOk()
        ->assertJsonPath('month', '2026-10')
        ->assertJsonPath('perMonth', 5)
        ->assertJsonPath('suggestions.0.counsellorId', $counsellor->id);

    $this->actingAs($admin)->getJson(route('admin.recognition.suggestions', ['month' => '2026-11']))->assertOk()->assertJsonPath('suggestions', []);
    $this->actingAs($admin)->getJson(route('admin.recognition.suggestions', ['month' => '2026-12']))->assertUnprocessable()->assertJsonValidationErrors('month');
    $this->actingAs($admin)->getJson(route('admin.recognition.suggestions', ['month' => '2026-09']))->assertUnprocessable()->assertJsonValidationErrors('month');
    $this->actingAs($admin)->getJson(route('admin.recognition.suggestions', ['month' => '2026-1']))->assertUnprocessable()->assertJsonValidationErrors('month');
});

// ---- Decisions ----

test('approving snapshots the stars and rank, sends the consent request and the notification, and records it', function () {
    $counsellor = rbkCounsellor();
    rbkStars($counsellor, 3);
    $admin = rbkAdmin();

    rbkDecide($this, 'approve', $counsellor, $admin)
        ->assertOk()
        ->assertJsonPath('recognition.decision', 'approved')
        ->assertJsonPath('recognition.consent', 'pending')
        ->assertJsonPath('recognition.month', '2026-10');

    $recognition = CounsellorRecognition::sole();
    expect($recognition)
        ->counsellor_id->toBe($counsellor->id)
        ->month->toBe('2026-10-01')
        ->decision->toBe(CounsellorRecognitionDecisionEnum::approved)
        ->consent->toBe(CounsellorRecognitionConsentEnum::pending)
        ->added_manually->toBeFalse()
        ->stars_count->toBe(3)
        ->rank->toBe(1)
        ->decided_by_id->toBe($admin->id)
        ->and(rbkEvents($recognition))->toBe(['approved'])
        ->and($recognition->events()->first()->user_id)->toBe($admin->id);

    $request = rbkConsentRequest($recognition);
    expect($request)
        ->from_type->toBe(User::class)
        ->from_id->toBe($admin->id)
        ->to_type->toBe(Counsellor::class)
        ->to_id->toBe($counsellor->id)
        ->status->toBe(RequestStatusEnum::pending->value)
        ->data->toBe(['month' => '2026-10']);

    Notification::assertSentTo($counsellor, CounsellorRecognisedNotification::class, function ($notification) use ($counsellor, $recognition) {
        $data = $notification->toArray($counsellor);
        $entry = CounsellorRecognisedNotification::present($data);
        $mail = $notification->toMail($counsellor);

        return $data['recognitionId'] === $recognition->id
            && $entry->title === "You've been recognised as a Counsellor of the month for October 2026"
            && $entry->url === route('counsellor.dashboard')
            && $mail->subject === "You've been recognised as a Counsellor of the month for October 2026";
    });

    // It's a counsellor's kind of notification they can switch off (SCRUM-40).
    expect(NotificationCategories::for(new CounsellorRecognisedNotification($recognition, $request)))->toBe(NotificationCategoryEnum::recognition)
        ->and(NotificationCategoryEnum::recognition->forCounsellorsOnly())->toBeTrue();

    // It waits on the counsellor's dashboard, and they see the month only -- the approver by name only.
    $listed = $this->actingAs($counsellor->user)->get(route('counsellor.dashboard'))->viewData('page')['props']['dashboard']['needsAction'];
    expect($listed['total'])->toBe(1)
        ->and($listed['items'][0]['recognition'])->toBe(['month' => '2026-10'])
        ->and($listed['items'][0]['for'])->toBe(['id' => $recognition->id])
        // Sent by the platform: which super administrator approved it is never shown.
        ->and($listed['items'][0]['from'])->toBe(['fullName' => 'TalkTherapy', 'isUser' => false])
        ->and($listed['items'][0]['isRespondent'])->toBeTrue();
});

test('skipping records the decision with no consent', function () {
    rbkStars($counsellor = rbkCounsellor(), 2);

    rbkDecide($this, 'skip', $counsellor)->assertOk()->assertJsonPath('recognition.decision', 'skipped');

    $recognition = CounsellorRecognition::sole();
    expect($recognition->decision)->toBe(CounsellorRecognitionDecisionEnum::skipped)
        ->and($recognition->consent)->toBeNull()
        ->and($recognition->stars_count)->toBe(2)
        ->and(rbkEvents($recognition))->toBe(['skipped'])
        ->and(rbkConsentRequest($recognition))->toBeNull();
    Notification::assertNothingSent();

    // Only a suggested (or already decided) counsellor can be skipped.
    rbkDecide($this, 'skip', rbkCounsellor())->assertUnprocessable()->assertJsonValidationErrors('counsellor_id');
});

test('adding approves an eligible counsellor who was not suggested', function () {
    config(['recognition.per_month' => 1]);
    rbkStars($top = rbkCounsellor(), 5);
    rbkStars($second = rbkCounsellor(), 2);
    $noStars = rbkCounsellor();

    // Approve is for suggestions; add is for the rest.
    rbkDecide($this, 'approve', $second)->assertUnprocessable()->assertJsonValidationErrors('counsellor_id');
    rbkDecide($this, 'add', $top)->assertUnprocessable()->assertJsonValidationErrors('counsellor_id');

    config(['recognition.per_month' => 3]);
    rbkDecide($this, 'add', $noStars)->assertOk()->assertJsonPath('recognition.addedManually', true);

    $recognition = $noStars->recognitions()->sole();
    expect($recognition)
        ->added_manually->toBeTrue()
        ->stars_count->toBe(0)
        ->rank->toBeNull()
        ->and(rbkEvents($recognition))->toBe(['added'])
        ->and(rbkConsentRequest($recognition))->not->toBeNull();
    Notification::assertSentTo($noStars, CounsellorRecognisedNotification::class);

    // Still only someone eligible now.
    $lapsed = rbkCounsellor(['verified_at' => now()->subYear(), 'verification_expires_at' => now()->subDay()]);
    $suspended = rbkCounsellor();
    rbkSuspend($suspended);
    $reported = rbkCounsellor();
    rbkReport($reported->user, rbkCounsellor(), ['counsellorId' => $reported->id]);

    foreach ([$lapsed, $suspended, $reported] as $ineligible) {
        rbkDecide($this, 'add', $ineligible)->assertUnprocessable()->assertJsonValidationErrors('counsellor_id');
    }
    expect(CounsellorRecognition::count())->toBe(1);
});

test('no more than the monthly cap are approved, however they were chosen', function () {
    config(['recognition.per_month' => 2]);
    $tied = collect(range(1, 3))->map(fn () => rbkCounsellor());
    $tied->each(fn (Counsellor $counsellor) => rbkStars($counsellor, 3));

    rbkDecide($this, 'approve', $tied[0])->assertOk();
    rbkDecide($this, 'approve', $tied[1])->assertOk();
    rbkDecide($this, 'approve', $tied[2])->assertUnprocessable()->assertJsonValidationErrors('counsellor_id');
    rbkDecide($this, 'add', rbkCounsellor())->assertUnprocessable()->assertJsonValidationErrors('counsellor_id');

    // Another month has its own cap.
    rbkStars($november = rbkCounsellor(), 1, '2026-11-10 10:00:00');
    rbkDecide($this, 'approve', $november, month: '2026-11')->assertOk();

    // Skipping one frees a place.
    rbkDecide($this, 'skip', $tied[0])->assertOk();
    rbkDecide($this, 'approve', $tied[2])->assertOk();

    expect(CounsellorRecognition::query()->where('month', '2026-10-01')->where('decision', 'approved')->pluck('counsellor_id')->all())
        ->toEqualCanonicalizing([$tied[1]->id, $tied[2]->id]);
});

test('one row per counsellor per month, and repeating a decision changes nothing', function () {
    rbkStars($counsellor = rbkCounsellor(), 2);

    rbkDecide($this, 'approve', $counsellor)->assertOk();
    rbkDecide($this, 'approve', $counsellor)->assertOk();
    rbkDecide($this, 'skip', $counsellor)->assertOk();
    rbkDecide($this, 'skip', $counsellor)->assertOk();

    $recognition = CounsellorRecognition::sole();
    expect(rbkEvents($recognition))->toBe(['approved', 'skipped'])
        ->and(Request::query()->where('type', RequestTypeEnum::counsellorRecognition->value)->count())->toBe(1);
    Notification::assertSentToTimes($counsellor, CounsellorRecognisedNotification::class, 1);

    expect(fn () => (new CounsellorRecognition)->forceFill([
        'counsellor_id' => $counsellor->id, 'month' => '2026-10-01', 'decision' => 'skipped',
    ])->save())->toThrow(UniqueConstraintViolationException::class);
});

test('changing a decision updates the row; skipping an approval closes its pending consent request', function () {
    rbkStars($counsellor = rbkCounsellor(), 2);

    rbkDecide($this, 'approve', $counsellor)->assertOk();
    $recognition = CounsellorRecognition::sole();
    $first = rbkConsentRequest($recognition);

    rbkDecide($this, 'skip', $counsellor)->assertOk();
    expect($recognition->fresh())
        ->decision->toBe(CounsellorRecognitionDecisionEnum::skipped)
        ->consent->toBeNull()
        ->and($first->fresh()->status)->toBe(RequestStatusEnum::inconsequencial->value);

    // Answering the closed request is refused.
    $this->actingAs($counsellor->user)->postJson(route('requests.respond', ['requestId' => $first->id]), ['response' => 'accepted'])->assertStatus(422);

    rbkDecide($this, 'approve', $counsellor)->assertOk();
    $second = rbkConsentRequest($recognition);
    expect($recognition->fresh())
        ->decision->toBe(CounsellorRecognitionDecisionEnum::approved)
        ->consent->toBe(CounsellorRecognitionConsentEnum::pending)
        ->and($second->id)->not->toBe($first->id)
        ->and($second->status)->toBe(RequestStatusEnum::pending->value)
        ->and(rbkEvents($recognition))->toBe(['approved', 'skipped', 'approved'])
        ->and($recognition->events()->get()->pluck('data')->all())->toBe([
            ['stars' => 2, 'rank' => 1],
            ['from' => 'approved'],
            ['stars' => 2, 'rank' => 1, 'from' => 'skipped'],
        ]);
});

// ---- Who may decide ----

test('an ordinary admin gets a 403 with no field errors, and nothing changes', function () {
    rbkStars($counsellor = rbkCounsellor(), 2);
    $admin = rbkAdmin(super: false);

    foreach (['approve', 'skip', 'add'] as $action) {
        rbkDecide($this, $action, $counsellor, $admin)->assertForbidden()->assertJsonMissingPath('errors');
        // Refused before validation: an invalid body is still a 403.
        $this->actingAs($admin)->postJson(route("admin.recognition.{$action}"), ['month' => 'nope'])->assertForbidden()->assertJsonMissingPath('errors');
    }
    // SCRUM-474: viewing is open to any administrator (CounsellorRecognitionAdminPageTest); deciding is not.

    expect(CounsellorRecognition::count())->toBe(0);
});

test('a guest and an ordinary user are refused, and every change is throttled', function () {
    rbkStars($counsellor = rbkCounsellor(), 2);

    $this->postJson(route('admin.recognition.approve'), ['month' => '2026-10', 'counsellor_id' => $counsellor->id])->assertUnauthorized();
    $this->post(route('admin.recognition.approve'), ['month' => '2026-10', 'counsellor_id' => $counsellor->id])->assertRedirect(route('login'));
    $this->getJson(route('admin.recognition.suggestions'))->assertUnauthorized();
    $this->postJson(route('counsellor.recognitions.consent', ['recognition' => 1]), ['action' => 'withdraw'])->assertUnauthorized();

    $user = User::factory()->adult()->create();
    $this->actingAs($user)->post(route('admin.recognition.approve'), ['month' => '2026-10', 'counsellor_id' => $counsellor->id])->assertRedirect(route('home'));
    expect(CounsellorRecognition::count())->toBe(0);

    $routes = app('router')->getRoutes();
    foreach (['admin.recognition.suggestions', 'admin.recognition.approve', 'admin.recognition.skip', 'admin.recognition.add', 'counsellor.recognitions.consent'] as $name) {
        expect(collect($routes->getByName($name)->gatherMiddleware())->contains(fn ($middleware) => str_starts_with($middleware, 'throttle:')))->toBeTrue($name);
    }
});

test('a month must be YYYY-MM, and this month or last month only', function () {
    rbkStars($counsellor = rbkCounsellor(), 2, '2026-11-02 10:00:00');
    rbkStars($earlier = rbkCounsellor(), 2, '2026-09-02 10:00:00');

    // Never back-dated further (a route to waivers), nor ahead.
    foreach (['2026-09', '2025-11', '2026-12', '2027-01', '26-10', '2026-13', '2026-00', ''] as $month) {
        rbkDecide($this, 'add', $earlier, month: $month)->assertUnprocessable()->assertJsonValidationErrors('month');
        rbkDecide($this, 'approve', $counsellor, month: $month)->assertUnprocessable()->assertJsonValidationErrors('month');
    }

    // The current month is allowed.
    rbkDecide($this, 'approve', $counsellor, month: '2026-11')->assertOk();
    expect(CounsellorRecognition::sole()->month)->toBe('2026-11-01');
});

// ---- Consent ----

test('only the counsellor it is addressed to answers the consent request', function (string $response, CounsellorRecognitionConsentEnum $consent, string $event) {
    rbkStars($counsellor = rbkCounsellor(), 2);
    $admin = rbkAdmin();
    rbkDecide($this, 'approve', $counsellor, $admin)->assertOk();
    $recognition = CounsellorRecognition::sole();
    $request = rbkConsentRequest($recognition);

    // Not another counsellor, not the super administrator who sent it, not another admin.
    foreach ([rbkCounsellor()->user, $admin, rbkAdmin(super: false)] as $other) {
        $this->actingAs($other)->postJson(route('requests.respond', ['requestId' => $request->id]), ['response' => 'accepted'])->assertStatus(422);
    }
    expect($recognition->fresh()->consent)->toBe(CounsellorRecognitionConsentEnum::pending);

    $this->actingAs($counsellor->user)->postJson(route('requests.respond', ['requestId' => $request->id]), ['response' => $response])->assertSuccessful();

    expect($recognition->fresh()->consent)->toBe($consent)
        ->and($request->fresh()->status)->toBe(strtoupper($response))
        ->and($request->fresh()->responded_by_id)->toBe($counsellor->user_id)
        ->and(rbkEvents($recognition))->toBe(['approved', $event])
        ->and($recognition->events()->get()->last()->user_id)->toBe($counsellor->user_id);
})->with([
    'accepted' => ['accepted', CounsellorRecognitionConsentEnum::accepted, 'consent_accepted'],
    'declined' => ['rejected', CounsellorRecognitionConsentEnum::declined, 'consent_declined'],
]);

test('the counsellor withdraws and restores their own consent, and nobody else can', function () {
    rbkStars($counsellor = rbkCounsellor(), 2);
    rbkDecide($this, 'approve', $counsellor)->assertOk();
    $recognition = CounsellorRecognition::sole();
    $request = rbkConsentRequest($recognition);
    $consent = fn (string $action, ?User $user = null) => $this->actingAs($user ?? $counsellor->user)
        ->postJson(route('counsellor.recognitions.consent', ['recognition' => $recognition->id]), ['action' => $action]);

    // Pending can't be restored; withdrawing it closes the request.
    $consent('restore')->assertUnprocessable()->assertJsonValidationErrors('action');
    $consent('withdraw')->assertOk()->assertJsonPath('recognition.consent', 'withdrawn')->assertJsonMissingPath('recognition.starsCount');
    expect($request->fresh()->status)->toBe(RequestStatusEnum::inconsequencial->value);

    $consent('withdraw')->assertUnprocessable();
    $consent('restore')->assertOk()->assertJsonPath('recognition.consent', 'accepted');
    $consent('withdraw')->assertOk()->assertJsonPath('recognition.consent', 'withdrawn');

    // Someone else's is "not found", like one that doesn't exist; nothing changes.
    $consent('restore', rbkCounsellor()->user)->assertNotFound();
    $consent('restore', rbkAdmin())->assertNotFound();
    $this->actingAs($counsellor->user)->postJson(route('counsellor.recognitions.consent', ['recognition' => 999999]), ['action' => 'restore'])->assertNotFound();
    $consent('pause')->assertUnprocessable()->assertJsonValidationErrors('action');

    expect($recognition->fresh()->consent)->toBe(CounsellorRecognitionConsentEnum::withdrawn)
        ->and(rbkEvents($recognition))->toBe(['approved', 'consent_withdrawn', 'consent_restored', 'consent_withdrawn'])
        ->and($recognition->events()->get()->pluck('data')->all())->toBe([
            ['stars' => 2, 'rank' => 1], ['from' => 'pending'], ['from' => 'withdrawn'], ['from' => 'accepted'],
        ]);

    // A declined one can be restored too; a skipped one can't be changed.
    rbkStars($other = rbkCounsellor(), 1);
    rbkDecide($this, 'approve', $other)->assertOk();
    $declined = $other->recognitions()->sole();
    $this->actingAs($other->user)->postJson(route('requests.respond', ['requestId' => rbkConsentRequest($declined)->id]), ['response' => 'rejected'])->assertSuccessful();
    $this->actingAs($other->user)->postJson(route('counsellor.recognitions.consent', ['recognition' => $declined->id]), ['action' => 'restore'])
        ->assertOk()->assertJsonPath('recognition.consent', 'accepted');

    rbkDecide($this, 'skip', $other)->assertOk();
    $this->actingAs($other->user)->postJson(route('counsellor.recognitions.consent', ['recognition' => $declined->id]), ['action' => 'withdraw'])
        ->assertUnprocessable();
});

test('a suspension leaves a pending consent pending', function () {
    rbkStars($counsellor = rbkCounsellor(), 2);
    rbkDecide($this, 'approve', $counsellor)->assertOk();
    $recognition = CounsellorRecognition::sole();

    rbkSuspend($counsellor);

    expect($recognition->fresh()->consent)->toBe(CounsellorRecognitionConsentEnum::pending)
        ->and(rbkConsentRequest($recognition)->status)->toBe(RequestStatusEnum::pending->value)
        ->and(RequestTypeEnum::counsellingTypes())->not->toContain(RequestTypeEnum::counsellorRecognition->value);
});

// ---- Review and security fixes ----

test('a super admin can\'t decide about their own counsellor profile, but another super admin can', function () {
    $admin = rbkAdmin();
    $own = rbkCounsellor(['user_id' => $admin->id]);
    rbkStars($own, 3);

    foreach (['approve', 'add', 'skip'] as $action) {
        rbkDecide($this, $action, $own, $admin)->assertForbidden()->assertJsonPath('message', CounsellorRecognitionService::NOT_YOURS_TO_DECIDE);
    }
    expect(CounsellorRecognition::count())->toBe(0);

    rbkDecide($this, 'approve', $own, rbkAdmin())->assertOk();
});

test('the service refuses a month the form would refuse', function () {
    rbkStars($counsellor = rbkCounsellor(), 2, '2026-09-02 10:00:00');

    expect(fn () => CounsellorRecognitionService::new()->add(CounsellorRecognitionDTO::new()->fromArray([
        'user' => rbkAdmin(), 'month' => '2026-09', 'counsellorId' => $counsellor->id,
    ])))->toThrow(ValidationException::class);
});

test('a consent request that no longer waits on an answer is refused, and nobody is recorded as answering it', function () {
    rbkStars($counsellor = rbkCounsellor(), 2);
    rbkDecide($this, 'approve', $counsellor)->assertOk();
    $recognition = CounsellorRecognition::sole();
    $request = rbkConsentRequest($recognition);

    // No answer at all is not a decline.
    $this->actingAs($counsellor->user)->postJson(route('requests.respond', ['requestId' => $request->id]), [])
        ->assertStatus(422)->assertJsonPath('error', RespondToCounsellorRecognitionRequestAction::NO_ANSWER);

    // The recognition moved on while the request stayed open.
    $recognition->forceFill(['consent' => CounsellorRecognitionConsentEnum::accepted])->save();

    $this->actingAs($counsellor->user)->postJson(route('requests.respond', ['requestId' => $request->id]), ['response' => 'rejected'])
        ->assertStatus(422)->assertJsonPath('error', RespondToCounsellorRecognitionRequestAction::NO_LONGER_WAITING);

    expect($request->fresh())
        ->status->toBe(RequestStatusEnum::pending->value)
        ->responded_by_id->toBeNull()
        ->responded_at->toBeNull()
        ->and($recognition->fresh()->consent)->toBe(CounsellorRecognitionConsentEnum::accepted)
        ->and(rbkEvents($recognition))->toBe(['approved']);
});

test('switching off the recognition kind stops its email and in-app notice', function () {
    rbkStars($counsellor = rbkCounsellor(), 2);
    rbkDecide($this, 'approve', $counsellor)->assertOk();
    $recognition = CounsellorRecognition::sole();
    $notification = new CounsellorRecognisedNotification($recognition, rbkConsentRequest($recognition));
    $sends = fn (string $channel) => app('events')->until(new NotificationSending($counsellor->fresh(), $notification, $channel)) !== false;

    expect($sends('mail'))->toBeTrue()->and($sends('database'))->toBeTrue();

    $counsellor->user->forceFill(['notification_channels' => ['recognition' => ['mail' => false, 'database' => false]]])->save();

    expect($sends('mail'))->toBeFalse()->and($sends('database'))->toBeFalse();
});

test('a decided counsellor since deleted or anonymised is listed under others, without their name', function () {
    rbkStars($deleted = rbkCounsellor(), 3);
    rbkStars($closed = rbkCounsellor(), 2);
    rbkDecide($this, 'approve', $deleted)->assertOk();
    rbkDecide($this, 'approve', $closed)->assertOk();

    $deleted->delete();
    // As AnonymiseClosedAccountAction leaves a closed account: the profile's name gone, the account anonymised.
    $closed->forceFill(['name' => null])->saveQuietly();
    $closed->user->forceFill(['anonymised_at' => now()])->saveQuietly();
    $closed->user->delete();

    $result = rbkSuggest();
    $others = collect($result['others'])->keyBy('counsellorId');

    expect($result['suggestions'])->toBe([])
        ->and($others[$deleted->id])->toMatchArray(['decision' => 'approved', 'stars' => 0, 'rank' => null])
        ->and($others[$deleted->id]['flags']['deleted'])->toBeTrue()
        ->and($others[$closed->id]['flags']['deleted'])->toBeTrue()
        ->and($others[$closed->id]['name'])->toBe(ConstantsEnum::deletedUserLabel->value);
});

test('with a cap of 0 nobody can be added or approved', function () {
    config(['recognition.per_month' => 0]);
    rbkStars($counsellor = rbkCounsellor(), 2);

    expect(rbkSuggest()['suggestions'])->toBe([]);
    rbkDecide($this, 'add', $counsellor)->assertUnprocessable()->assertJsonValidationErrors('counsellor_id');
    rbkDecide($this, 'approve', $counsellor)->assertUnprocessable()->assertJsonValidationErrors('counsellor_id');
    expect(CounsellorRecognition::count())->toBe(0);
});

test('the suggestions take the same number of queries however many counsellors and reports there are', function () {
    $reporter = rbkCounsellor();
    $admin = rbkAdmin();

    // Each round: a suggested counsellor, one with open reports about them (by data, as a counsellor and as a user),
    // one suspended and decided earlier (an `others` row), and an ambiguous report.
    $seed = function (int $rounds) use ($reporter, $admin) {
        foreach (range(1, $rounds) as $i) {
            rbkStars(rbkCounsellor(), 2);

            rbkStars($reported = rbkCounsellor(), 2);
            rbkReport($reported->user, $reporter, ['counsellorId' => $reported->id]);
            rbkReport($reported->user, $reporter->user, ['userId' => $reported->user_id], 'under_review');
            rbkReport($reported->user, $reporter, ['counsellorId' => $reported->id, 'userId' => User::factory()->create()->id]);

            $decided = rbkCounsellor();
            (new CounsellorRecognition)->forceFill([
                'counsellor_id' => $decided->id, 'month' => '2026-10-01', 'decision' => 'skipped', 'decided_by_id' => $admin->id,
            ])->save();
            rbkSuspend($decided);
        }
    };
    $queries = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = rbkSuggest();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$count, count($result['suggestions']), count($result['others'])];
    };

    config(['recognition.per_month' => 20]);
    $seed(1);
    [$few, $suggested, $others] = $queries();
    $seed(4);
    [$many, $moreSuggested, $moreOthers] = $queries();

    expect([$suggested, $others, $moreSuggested, $moreOthers])->toBe([1, 1, 5, 5])
        ->and($many)->toBe($few);
});
