<?php

use App\Actions\Counsellor\PurgeExpiredSoftDeletedCounsellorsAction;
use App\Actions\Organization\RecordOrganizationInvoiceLineForSessionAction;
use App\Actions\Payout\GetCounsellorPayoutOverviewAction;
use App\Actions\Recognition\RecordCounsellorRecognitionEventAction;
use App\Actions\Transaction\ChargeOrganizationForModelAction;
use App\Actions\Transaction\GenerateCounsellorEarningsAction;
use App\DTOs\CounsellorRecognitionDTO;
use App\DTOs\PlatformFeeWaiverDTO;
use App\DTOs\TransactionDTO;
use App\Enums\AdministratorTypeEnum;
use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Enums\CounsellorRecognitionDecisionEnum;
use App\Enums\CounsellorRecognitionEventEnum;
use App\Enums\NotificationCategoryEnum;
use App\Enums\OrganizationAdminRoleEnum;
use App\Enums\OrganizationCounsellorCompensationBasisEnum;
use App\Enums\OrganizationCounsellorCompensationTypeEnum;
use App\Enums\OrganizationCounsellorStatusEnum;
use App\Enums\OrganizationMemberBillingModeEnum;
use App\Enums\PlatformFeeWaiverEventEnum;
use App\Enums\SuspensionScopeEnum;
use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapyPerPaymentEnum;
use App\Enums\TherapySessionTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Enums\TransactionStatusEnum;
use App\Exceptions\PlatformFeeWaiverException;
use App\Exceptions\UserException;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\CounsellorRecognition;
use App\Models\CounsellorRecognitionEvent;
use App\Models\GroupTherapy;
use App\Models\Organization;
use App\Models\OrganizationCounsellor;
use App\Models\OrganizationCounsellorCompensation;
use App\Models\OrganizationInvoiceLine;
use App\Models\OrganizationMember;
use App\Models\OrganizationMemberBillingConfig;
use App\Models\OrganizationPaymentInstrument;
use App\Models\PlatformFeeWaiver;
use App\Models\PlatformFeeWaiverEvent;
use App\Models\Report;
use App\Models\Session;
use App\Models\Suspension;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\CounsellorFeeWaiverGrantedNotification;
use App\Services\CounsellorRecognitionService;
use App\Services\PlatformFeeWaiverService;
use App\Support\NotificationCategories;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

// TT-7.11e/SCRUM-475: a super administrator gives one counsellor their own commission-free period (optionally for an
// approved recognition), ends it now or deletes it before it starts; every change to any period is an event.

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-20 12:00:00', 'UTC'));
    config(['settings.platform_fee_percentage' => 10]);
    Notification::fake();
});

afterEach(function () {
    Carbon::setTestNow();
});

function cfwAdmin(bool $super = true): User
{
    return User::factory()->adult()
        ->has(Administrator::factory()->state([
            'type' => $super ? AdministratorTypeEnum::super->value : AdministratorTypeEnum::normal->value,
        ]))
        ->create();
}

function cfwCounsellor(array $state = []): Counsellor
{
    return Counsellor::factory()->create(array_merge(['user_id' => User::factory()->adult()], $state));
}

function cfwValid(array $overrides = []): array
{
    return array_merge([
        'starts_at' => '2026-12-01T00:00',
        'ends_at' => '2027-01-01T00:00',
        'reason' => 'Counsellor of the month',
    ], $overrides);
}

function cfwWaiver(?Counsellor $counsellor, string $startsAt, ?string $endsAt, array $attributes = []): PlatformFeeWaiver
{
    $factory = PlatformFeeWaiver::factory()->between(Carbon::parse($startsAt, 'UTC'), $endsAt ? Carbon::parse($endsAt, 'UTC') : null);

    return ($counsellor ? $factory->forCounsellor($counsellor) : $factory)->create($attributes);
}

function cfwRecognition(Counsellor $counsellor, CounsellorRecognitionDecisionEnum $decision = CounsellorRecognitionDecisionEnum::approved): CounsellorRecognition
{
    return tap((new CounsellorRecognition)->forceFill([
        'counsellor_id' => $counsellor->id,
        'month' => '2026-10-01',
        'decision' => $decision,
        'added_manually' => false,
        'stars_count' => 3,
        'rank' => 1,
        'decided_at' => now(),
    ]))->save();
}

function cfwSuspend(Counsellor $counsellor): void
{
    $report = new Report(['description' => 'Conduct']);
    $report->addedby()->associate(User::factory()->adult()->create());
    $report->reportable()->associate($counsellor);
    $report->save();

    Suspension::query()->forceCreate(['user_id' => $counsellor->user_id, 'report_id' => $report->id, 'scope' => SuspensionScopeEnum::counsellor->value,
        'public_reason' => 'Conduct', 'starts_at' => now()->subMinute(), 'ends_at' => now()->addDays(30)]);
}

function cfwOpenReport(Counsellor $counsellor, string $status = 'open'): void
{
    $client = User::factory()->adult()->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id]);
    $report = new Report(['description' => 'Something happened', 'data' => ['counsellorId' => $counsellor->id]]);
    $report->addedby()->associate($client);
    $report->reportable()->associate($therapy);
    $report->save();
    $report->forceFill(['status' => $status])->save();
}

function cfwStore($test, Counsellor $counsellor, array $data = [], ?User $admin = null)
{
    return $test->actingAs($admin ?? cfwAdmin())->postJson(route('admin.counsellors.fee-waivers.store', ['counsellor' => $counsellor->id]), cfwValid($data));
}

function cfwDto(User $user, ?int $counsellorId, ?int $waiverId = null): PlatformFeeWaiverDTO
{
    return PlatformFeeWaiverDTO::new()->fromArray(['user' => $user, 'counsellorId' => $counsellorId, 'waiverId' => $waiverId]);
}

// ---- Creating ----

test('a super admin gives a counsellor their own period, recorded as theirs, with its event', function () {
    $admin = cfwAdmin();
    $counsellor = cfwCounsellor();

    cfwStore($this, $counsellor, ['reason' => '  Counsellor of the month  '], $admin)
        ->assertOk()
        ->assertJsonPath('waiver.startsAt', '2026-12-01T00:00:00Z')
        ->assertJsonPath('waiver.status', 'upcoming');

    $waiver = PlatformFeeWaiver::query()->sole();
    expect($waiver->counsellor_id)->toBe($counsellor->id)
        ->and($waiver->created_by_id)->toBe($admin->id)
        ->and($waiver->reason)->toBe('Counsellor of the month')
        ->and($waiver->ends_at->toDateTimeString())->toBe('2027-01-01 00:00:00');

    $event = PlatformFeeWaiverEvent::query()->sole();
    expect($event->event)->toBe(PlatformFeeWaiverEventEnum::created)
        ->and($event->platform_fee_waiver_id)->toBe($waiver->id)
        ->and($event->counsellor_id)->toBe($counsellor->id)
        ->and($event->user_id)->toBe($admin->id)
        ->and($event->data)->toBe(['starts_at' => '2026-12-01T00:00:00Z', 'ends_at' => '2027-01-01T00:00:00Z']);
});

test('the period\'s fields follow the platform-wide rules', function (array $data, string $field) {
    cfwStore($this, cfwCounsellor(), $data)->assertUnprocessable()->assertJsonValidationErrors($field);

    expect(PlatformFeeWaiver::query()->count())->toBe(0);
})->with([
    'no start' => [['starts_at' => null], 'starts_at'],
    'backdated past the minute' => [['starts_at' => '2026-11-20T11:58:00'], 'starts_at'],
    'start over two years out' => [['starts_at' => '2028-11-21T00:00', 'ends_at' => '2028-11-22T00:00'], 'starts_at'],
    'no end' => [['ends_at' => null], 'ends_at'],
    'end before the start' => [['ends_at' => '2026-11-30T00:00'], 'ends_at'],
    'end equal to the start' => [['ends_at' => '2026-12-01T00:00'], 'ends_at'],
    'end over two years out' => [['ends_at' => '2028-11-21T00:00'], 'ends_at'],
    'no reason' => [['reason' => ''], 'reason'],
    'reason too long' => [['reason' => str_repeat('a', 501)], 'reason'],
    'reason not text' => [['reason' => ['a']], 'reason'],
    'a counsellor_id in the body' => [['counsellor_id' => 1], 'counsellor_id'],
    'a recognition id that is not a number' => [['counsellor_recognition_id' => 'abc'], 'counsellor_recognition_id'],
]);

test('a start within the minute\'s leeway is moved up to now', function () {
    cfwStore($this, cfwCounsellor(), ['starts_at' => '2026-11-20T11:59:30'])->assertOk();

    expect(PlatformFeeWaiver::query()->sole()->starts_at->toDateTimeString())->toBe('2026-11-20 12:00:00');
});

test('the service itself refuses an end that isn\'t after the start', function () {
    $counsellor = cfwCounsellor();

    expect(fn () => PlatformFeeWaiverService::new()->createForCounsellor(cfwDto(cfwAdmin(), $counsellor->id), [
        'starts_at' => now()->toImmutable(), 'ends_at' => now()->toImmutable(), 'reason' => 'x',
    ]))->toThrow(ValidationException::class);
});

test('the service enforces the period\'s bounds for every caller, not only the form', function (string $startsAt, string $endsAt, string $reason, string $field) {
    $values = ['starts_at' => Carbon::parse($startsAt, 'UTC')->toImmutable(), 'ends_at' => Carbon::parse($endsAt, 'UTC')->toImmutable(), 'reason' => $reason];

    foreach ([
        fn () => PlatformFeeWaiverService::new()->createForCounsellor(cfwDto(cfwAdmin(), cfwCounsellor()->id), $values),
        fn () => PlatformFeeWaiverService::new()->create(cfwDto(cfwAdmin(), null), $values),
    ] as $create) {
        try {
            $create();
            $this->fail('The period was accepted.');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey($field);
        }
    }

    expect(PlatformFeeWaiver::query()->count())->toBe(0);
})->with([
    'backdated beyond the 60 s leeway' => ['2026-11-20 11:58:59', '2026-12-01 00:00', 'x', 'starts_at'],
    'start two years out' => ['2028-11-20 12:00:00', '2028-12-01 00:00', 'x', 'starts_at'],
    'end over two years out' => ['2026-12-01 00:00', '2028-11-20 12:00:01', 'x', 'ends_at'],
    'end in the past with a clamped start' => ['2026-11-20 11:59:30', '2026-11-20 11:59:50', 'x', 'ends_at'],
    'blank reason' => ['2026-12-01 00:00', '2027-01-01 00:00', '   ', 'reason'],
    'reason too long' => ['2026-12-01 00:00', '2027-01-01 00:00', str_repeat('a', 501), 'reason'],
]);

test('the service moves a start within the leeway up to now, and trims the reason', function () {
    $waiver = PlatformFeeWaiverService::new()->createForCounsellor(cfwDto(cfwAdmin(), cfwCounsellor()->id), [
        'starts_at' => Carbon::parse('2026-11-20 11:59:01', 'UTC')->toImmutable(),
        'ends_at' => Carbon::parse('2026-12-01 00:00', 'UTC')->toImmutable(),
        'reason' => '  Kept  ',
    ]);

    expect($waiver->starts_at->toDateTimeString())->toBe('2026-11-20 12:00:00')->and($waiver->reason)->toBe('Kept');
});

test('a counsellor\'s periods can\'t overlap each other, but touching ends can', function () {
    $counsellor = cfwCounsellor();
    cfwWaiver($counsellor, '2026-12-01 00:00', '2027-01-01 00:00');

    cfwStore($this, $counsellor, ['starts_at' => '2026-12-31T23:59', 'ends_at' => '2027-01-10T00:00'])
        ->assertUnprocessable()->assertJsonValidationErrors(['starts_at' => 'overlaps']);
    cfwStore($this, $counsellor, ['starts_at' => '2026-11-25T00:00', 'ends_at' => '2026-12-01T00:01'])
        ->assertUnprocessable()->assertJsonValidationErrors('starts_at');

    // An open-ended one overlaps everything after its start.
    $other = cfwCounsellor();
    cfwWaiver($other, '2026-11-19 00:00', null);
    cfwStore($this, $other, ['starts_at' => '2028-01-01T00:00', 'ends_at' => '2028-02-01T00:00'])->assertUnprocessable();

    cfwStore($this, $counsellor, ['starts_at' => '2027-01-01T00:00', 'ends_at' => '2027-02-01T00:00'])->assertOk();
    cfwStore($this, $counsellor, ['starts_at' => '2026-11-25T00:00', 'ends_at' => '2026-12-01T00:00'])->assertOk();

    expect(PlatformFeeWaiver::query()->where('counsellor_id', $counsellor->id)->count())->toBe(3);
});

test('a counsellor\'s period may overlap a platform-wide period and another counsellor\'s', function () {
    cfwWaiver(null, '2026-11-19 00:00', '2026-12-15 00:00');
    cfwWaiver(cfwCounsellor(), '2026-12-01 00:00', '2027-01-01 00:00');

    cfwStore($this, cfwCounsellor())->assertOk();

    // And a platform-wide period may still be created over a counsellor's.
    $this->actingAs(cfwAdmin())->post(route('admin.settings.platform-fee-waivers.store'), cfwValid([
        'starts_at' => '2026-12-20T00:00', 'ends_at' => '2026-12-27T00:00',
    ]))->assertSessionHasNoErrors();

    expect(PlatformFeeWaiver::query()->count())->toBe(4);
});

test('an ordinary admin, a counsellor and a guest are refused before validation', function () {
    $counsellor = cfwCounsellor();
    $waiver = cfwWaiver($counsellor, '2026-11-19 00:00', '2026-12-01 00:00');

    cfwStore($this, $counsellor, ['reason' => ''], cfwAdmin(super: false))->assertForbidden();
    $this->actingAs(cfwAdmin(super: false))->postJson(route('admin.counsellors.fee-waivers.end', ['counsellor' => $counsellor->id, 'waiver' => $waiver->id]))->assertForbidden();
    $this->actingAs(cfwAdmin(super: false))->postJson(route('admin.counsellors.fee-waivers.delete', ['counsellor' => $counsellor->id, 'waiver' => $waiver->id]))->assertForbidden();
    $this->actingAs(cfwAdmin(super: false))->getJson(route('admin.counsellors.fee-waivers.index', ['counsellor' => $counsellor->id]))->assertForbidden();
    $this->actingAs(cfwAdmin(super: false))->getJson(route('admin.settings.platform-fee-waivers.events'))->assertForbidden();

    // The admin middleware turns a non-admin away before the request is read.
    $this->actingAs($counsellor->user)->postJson(route('admin.counsellors.fee-waivers.store', ['counsellor' => $counsellor->id]), cfwValid())->assertStatus(403);
    auth()->logout();
    $this->postJson(route('admin.counsellors.fee-waivers.store', ['counsellor' => $counsellor->id]), cfwValid())->assertUnauthorized();

    expect($waiver->fresh()->ends_at->toDateTimeString())->toBe('2026-12-01 00:00:00')
        ->and(PlatformFeeWaiver::query()->count())->toBe(1);
});

test('the service refuses anyone but a super admin too', function () {
    $counsellor = cfwCounsellor();

    expect(fn () => PlatformFeeWaiverService::new()->createForCounsellor(cfwDto(cfwAdmin(super: false), $counsellor->id), [
        'starts_at' => now()->addDay()->toImmutable(), 'ends_at' => now()->addDays(2)->toImmutable(), 'reason' => 'x',
    ]))->toThrow(UserException::class, PlatformFeeWaiverService::NOT_ALLOWED);
});

test('a super admin can\'t give their own counsellor profile a period', function () {
    $admin = cfwAdmin();
    $counsellor = cfwCounsellor(['user_id' => $admin->id]);

    cfwStore($this, $counsellor, [], $admin)
        ->assertForbidden()
        ->assertJsonPath('message', PlatformFeeWaiverService::NOT_FOR_YOURSELF);

    expect(PlatformFeeWaiver::query()->count())->toBe(0)->and(PlatformFeeWaiverEvent::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

test('only a verified, unsuspended, undeleted counsellor can be given a new period', function (Closure $make) {
    $counsellor = $make();

    cfwStore($this, $counsellor)->assertUnprocessable()->assertJsonValidationErrors(['counsellor' => 'verified']);

    expect(PlatformFeeWaiver::query()->count())->toBe(0);
    Notification::assertNothingSent();
})->with([
    'never verified' => fn () => cfwCounsellor(['verified_at' => null, 'verification_expires_at' => null]),
    'verification lapsed' => fn () => cfwCounsellor(['verified_at' => now()->subYear(), 'verification_expires_at' => now()->subDay()]),
    'verification revoked' => fn () => cfwCounsellor(['verification_revoked_at' => now()->subDay()]),
    'suspended' => fn () => tap(cfwCounsellor(), fn (Counsellor $counsellor) => cfwSuspend($counsellor)),
    'deleted' => fn () => tap(cfwCounsellor(), fn (Counsellor $counsellor) => $counsellor->delete()),
    'account deleted' => fn () => tap(cfwCounsellor(), fn (Counsellor $counsellor) => $counsellor->user->delete()),
]);

test('a counsellor that doesn\'t exist is not found', function () {
    $this->actingAs(cfwAdmin())->postJson(route('admin.counsellors.fee-waivers.store', ['counsellor' => 999999]), cfwValid())->assertNotFound();
    $this->actingAs(cfwAdmin())->getJson(route('admin.counsellors.fee-waivers.index', ['counsellor' => 999999]))->assertNotFound();
});

test('a counsellor under an open or under-review report can\'t be given a new period, linked or not', function (string $status, bool $linked) {
    $counsellor = cfwCounsellor();
    cfwOpenReport($counsellor, $status);

    cfwStore($this, $counsellor, $linked ? ['counsellor_recognition_id' => cfwRecognition($counsellor)->id] : [])
        ->assertUnprocessable()->assertJsonValidationErrors(['counsellor' => 'open report']);

    expect(PlatformFeeWaiver::query()->count())->toBe(0);
    Notification::assertNothingSent();
})->with([
    'open, plain' => ['open', false],
    'open, for a recognition' => ['open', true],
    'under review, plain' => ['under_review', false],
]);

test('a resolved report doesn\'t stop a period', function () {
    $counsellor = cfwCounsellor();
    cfwOpenReport($counsellor, 'resolved');

    cfwStore($this, $counsellor)->assertOk();
});

// ---- The recognition link ----

test('a period for an approved recognition is linked to it, both ways', function () {
    $counsellor = cfwCounsellor();
    $recognition = cfwRecognition($counsellor);

    cfwStore($this, $counsellor, ['counsellor_recognition_id' => $recognition->id])->assertOk();

    $waiver = PlatformFeeWaiver::query()->sole();
    expect($recognition->fresh()->platform_fee_waiver_id)->toBe($waiver->id)
        ->and($waiver->recognition->is($recognition))->toBeTrue()
        ->and(PlatformFeeWaiverEvent::query()->sole()->data['counsellor_recognition_id'])->toBe($recognition->id);

    // One period per recognition.
    cfwStore($this, $counsellor, ['starts_at' => '2027-02-01T00:00', 'ends_at' => '2027-03-01T00:00', 'counsellor_recognition_id' => $recognition->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['counsellor_recognition_id' => 'already']);
});

test('the recognition must be this counsellor\'s, and approved', function (string $case) {
    $counsellor = cfwCounsellor();
    $recognitionId = match ($case) {
        'other' => cfwRecognition(cfwCounsellor())->id,
        'skipped' => cfwRecognition($counsellor, CounsellorRecognitionDecisionEnum::skipped)->id,
        'missing' => 999999,
    };

    cfwStore($this, $counsellor, ['counsellor_recognition_id' => $recognitionId])
        ->assertUnprocessable()->assertJsonValidationErrors(['counsellor_recognition_id' => 'approved recognition of this counsellor']);

    expect(PlatformFeeWaiver::query()->count())->toBe(0);
})->with(['other', 'skipped', 'missing']);

// ---- Ending and deleting ----

test('a running period of a counsellor is ended now, with its event', function () {
    $admin = cfwAdmin();
    $counsellor = cfwCounsellor();
    $waiver = cfwWaiver($counsellor, '2026-11-19 00:00', '2026-12-01 00:00');

    $this->actingAs($admin)->postJson(route('admin.counsellors.fee-waivers.end', ['counsellor' => $counsellor->id, 'waiver' => $waiver->id]))
        ->assertOk()->assertJsonPath('waiver.status', PlatformFeeWaiver::CANCELLED);

    $waiver->refresh();
    expect($waiver->ends_at->toDateTimeString())->toBe('2026-11-20 12:00:00')
        ->and($waiver->ended_by_id)->toBe($admin->id);

    $event = PlatformFeeWaiverEvent::query()->sole();
    expect($event->event)->toBe(PlatformFeeWaiverEventEnum::ended)
        ->and($event->user_id)->toBe($admin->id)
        ->and($event->data)->toBe(['starts_at' => '2026-11-19T00:00:00Z', 'ends_at' => '2026-11-20T12:00:00Z', 'scheduled_ends_at' => '2026-12-01T00:00:00Z']);
});

test('only a running period can be ended', function (string $startsAt, ?string $endsAt) {
    $counsellor = cfwCounsellor();
    $waiver = cfwWaiver($counsellor, $startsAt, $endsAt);

    $this->actingAs(cfwAdmin())->postJson(route('admin.counsellors.fee-waivers.end', ['counsellor' => $counsellor->id, 'waiver' => $waiver->id]))
        ->assertUnprocessable()->assertJsonValidationErrors('waiver');

    expect(PlatformFeeWaiverEvent::query()->count())->toBe(0);
})->with([
    'upcoming' => ['2026-12-01 00:00', '2027-01-01 00:00'],
    'ended' => ['2026-10-01 00:00', '2026-11-01 00:00'],
]);

test('an upcoming period of a counsellor is deleted; its event keeps the dates, the reason and the recognition', function () {
    $admin = cfwAdmin();
    $counsellor = cfwCounsellor();
    $recognition = cfwRecognition($counsellor);
    cfwStore($this, $counsellor, ['reason' => 'For October', 'counsellor_recognition_id' => $recognition->id], $admin)->assertOk();
    $waiver = PlatformFeeWaiver::query()->sole();

    $this->actingAs($admin)->postJson(route('admin.counsellors.fee-waivers.delete', ['counsellor' => $counsellor->id, 'waiver' => $waiver->id]))->assertOk();

    expect(PlatformFeeWaiver::query()->count())->toBe(0)
        ->and($recognition->fresh()->platform_fee_waiver_id)->toBeNull();

    $events = PlatformFeeWaiverEvent::query()->orderBy('id')->get();
    expect($events->pluck('event')->all())->toBe([PlatformFeeWaiverEventEnum::created, PlatformFeeWaiverEventEnum::deleted])
        ->and($events->pluck('platform_fee_waiver_id')->unique()->all())->toBe([$waiver->id])
        ->and($events[1]->counsellor_id)->toBe($counsellor->id)
        ->and($events[1]->data)->toBe([
            'starts_at' => '2026-12-01T00:00:00Z', 'ends_at' => '2027-01-01T00:00:00Z',
            'reason' => 'For October', 'counsellor_recognition_id' => $recognition->id,
        ]);
});

test('only an upcoming period starting over a minute from now can be deleted', function (string $startsAt) {
    $counsellor = cfwCounsellor();
    $waiver = cfwWaiver($counsellor, $startsAt, '2027-01-01 00:00');

    $this->actingAs(cfwAdmin())->postJson(route('admin.counsellors.fee-waivers.delete', ['counsellor' => $counsellor->id, 'waiver' => $waiver->id]))
        ->assertUnprocessable()->assertJsonValidationErrors('waiver');

    expect($waiver->fresh())->not->toBeNull()->and(PlatformFeeWaiverEvent::query()->count())->toBe(0);
})->with([
    'running' => ['2026-11-19 00:00'],
    'starting in 60 seconds' => ['2026-11-20 12:01:00'],
]);

// ---- Scopes ----

test('a counsellor\'s route never reaches a platform-wide period or another counsellor\'s', function (string $action) {
    $counsellor = cfwCounsellor();
    $platform = cfwWaiver(null, '2026-11-19 00:00', '2026-12-01 00:00');
    $others = cfwWaiver(cfwCounsellor(), '2026-11-19 00:00', '2026-12-01 00:00');

    foreach ([$platform, $others] as $waiver) {
        $this->actingAs(cfwAdmin())->postJson(route("admin.counsellors.fee-waivers.{$action}", ['counsellor' => $counsellor->id, 'waiver' => $waiver->id]))
            ->assertNotFound()->assertJsonPath('message', PlatformFeeWaiverService::NOT_FOUND);
        expect($waiver->fresh()->ends_at->toDateTimeString())->toBe('2026-12-01 00:00:00');
    }
})->with(['end', 'delete']);

test('the platform-wide routes never reach a counsellor\'s period', function (string $action) {
    $waiver = cfwWaiver(cfwCounsellor(), '2026-11-19 00:00', '2026-12-01 00:00');

    $this->actingAs(cfwAdmin())->postJson(route("admin.settings.platform-fee-waivers.{$action}", ['waiver' => $waiver->id]))->assertNotFound();

    expect($waiver->fresh()->ended_by_id)->toBeNull();
})->with(['end', 'delete']);

// ---- Events for platform-wide periods ----

test('platform-wide periods write events too, and a deleted one\'s survive it', function () {
    $admin = cfwAdmin();

    $this->actingAs($admin)->post(route('admin.settings.platform-fee-waivers.store'), cfwValid(['ends_at' => '2026-12-08T00:00', 'reason' => 'Awareness week']))
        ->assertSessionHasNoErrors();
    $upcoming = PlatformFeeWaiver::query()->sole();
    $running = cfwWaiver(null, '2026-11-19 00:00', '2026-11-25 00:00');

    $this->actingAs($admin)->post(route('admin.settings.platform-fee-waivers.end', ['waiver' => $running->id]))->assertSessionHasNoErrors();
    $this->actingAs($admin)->post(route('admin.settings.platform-fee-waivers.delete', ['waiver' => $upcoming->id]))->assertSessionHasNoErrors();

    $events = PlatformFeeWaiverEvent::query()->orderBy('id')->get();
    expect($events->pluck('event')->all())->toBe([PlatformFeeWaiverEventEnum::created, PlatformFeeWaiverEventEnum::ended, PlatformFeeWaiverEventEnum::deleted])
        ->and($events->pluck('counsellor_id')->unique()->all())->toBe([null])
        ->and($events->pluck('user_id')->unique()->all())->toBe([$admin->id])
        ->and($events[2]->platform_fee_waiver_id)->toBe($upcoming->id)
        ->and($events[2]->data)->toBe(['starts_at' => '2026-12-01T00:00:00Z', 'ends_at' => '2026-12-08T00:00:00Z', 'reason' => 'Awareness week'])
        ->and(PlatformFeeWaiver::query()->find($upcoming->id))->toBeNull();

    $this->actingAs($admin)->getJson(route('admin.settings.platform-fee-waivers.events'))
        ->assertOk()
        ->assertJsonCount(3, 'events')
        ->assertJsonPath('events.0.event', 'deleted')
        ->assertJsonPath('events.0.reason', 'Awareness week')
        ->assertJsonPath('events.0.by', $admin->name)
        ->assertJsonPath('events.1.event', 'ended')
        ->assertJsonPath('events.1.scheduledEndsAt', '2026-11-25T00:00:00Z');
});

test('a refused change writes no event, and events can\'t be changed', function () {
    cfwWaiver(null, '2026-11-19 00:00', '2026-12-15 00:00');

    $this->actingAs(cfwAdmin())->post(route('admin.settings.platform-fee-waivers.store'), cfwValid())->assertSessionHasErrors('starts_at');
    expect(PlatformFeeWaiverEvent::query()->count())->toBe(0);

    cfwStore($this, cfwCounsellor())->assertOk();
    $event = PlatformFeeWaiverEvent::query()->sole();

    expect(fn () => $event->update(['event' => PlatformFeeWaiverEventEnum::deleted]))->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class);
});

// ---- The admin list (for the SCRUM-474 page) ----

test('the list shows a counsellor\'s own periods, their history, and flags any still applying once they\'re not eligible', function () {
    $admin = cfwAdmin();
    $counsellor = cfwCounsellor();
    $recognition = cfwRecognition($counsellor);
    cfwStore($this, $counsellor, ['counsellor_recognition_id' => $recognition->id, 'reason' => 'For October'], $admin)->assertOk();
    $running = cfwWaiver($counsellor, '2026-11-19 00:00', '2026-11-25 00:00');
    cfwWaiver($counsellor, '2026-10-01 00:00', '2026-10-08 00:00');
    cfwWaiver(null, '2026-11-19 00:00', '2026-11-25 00:00');
    cfwWaiver(cfwCounsellor(), '2026-11-19 00:00', '2026-11-25 00:00');

    $this->actingAs($admin)->getJson(route('admin.counsellors.fee-waivers.index', ['counsellor' => $counsellor->id]))
        ->assertOk()
        ->assertJsonPath('counsellor.eligible', true)
        ->assertJsonCount(3, 'waivers')
        ->assertJsonPath('waivers.0.reason', 'For October')
        ->assertJsonPath('waivers.0.recognitionId', $recognition->id)
        ->assertJsonPath('waivers.0.canDelete', true)
        ->assertJsonPath('waivers.0.needsReview', false)
        ->assertJsonPath('waivers.1.id', $running->id)
        ->assertJsonPath('waivers.1.canEnd', true)
        ->assertJsonCount(1, 'events');

    // Suspended later: nothing is ended, but the running and upcoming periods are flagged.
    cfwSuspend($counsellor);

    $this->actingAs($admin)->getJson(route('admin.counsellors.fee-waivers.index', ['counsellor' => $counsellor->id]))
        ->assertJsonPath('counsellor.eligible', false)
        ->assertJsonPath('counsellor.suspended', true)
        ->assertJsonPath('waivers.0.needsReview', true)
        ->assertJsonPath('waivers.1.needsReview', true)
        ->assertJsonPath('waivers.2.needsReview', false);

    expect($running->fresh()->status())->toBe(PlatformFeeWaiver::ACTIVE);

    // A deleted counsellor's running period can still be ended.
    $counsellor->delete();
    $this->actingAs($admin)->postJson(route('admin.counsellors.fee-waivers.end', ['counsellor' => $counsellor->id, 'waiver' => $running->id]))->assertOk();
    $this->actingAs($admin)->getJson(route('admin.counsellors.fee-waivers.index', ['counsellor' => $counsellor->id]))
        ->assertJsonPath('counsellor.deleted', true);
});

test('create, end and delete answer with the same period shape as the list', function () {
    $admin = cfwAdmin();
    $counsellor = cfwCounsellor();
    $keys = ['id', 'startsAt', 'endsAt', 'reason', 'status', 'createdBy', 'endedBy', 'deleted', 'canEnd', 'canDelete',
        'counsellorId', 'recognitionId', 'recognitionDecision', 'needsReview'];

    $created = cfwStore($this, $counsellor, [], $admin)->assertOk()->json('waiver');
    $listed = $this->actingAs($admin)->getJson(route('admin.counsellors.fee-waivers.index', ['counsellor' => $counsellor->id]))->json('waivers.0');
    $deleted = $this->actingAs($admin)->postJson(route('admin.counsellors.fee-waivers.delete', ['counsellor' => $counsellor->id, 'waiver' => $created['id']]))->assertOk()->json('waiver');
    $running = cfwWaiver($counsellor, '2026-11-19 00:00', '2026-11-25 00:00');
    $ended = $this->actingAs($admin)->postJson(route('admin.counsellors.fee-waivers.end', ['counsellor' => $counsellor->id, 'waiver' => $running->id]))->assertOk()->json('waiver');

    foreach ([$created, $listed, $deleted, $ended] as $waiver) {
        expect(array_keys($waiver))->toBe($keys);
    }
    expect($created)->toBe($listed)
        ->and($deleted)->toMatchArray(['id' => $created['id'], 'deleted' => true, 'canDelete' => false, 'canEnd' => false])
        ->and($ended)->toMatchArray(['status' => PlatformFeeWaiver::CANCELLED, 'deleted' => false, 'needsReview' => false]);
});

test('a period whose recognition is skipped afterwards is flagged, and not ended', function () {
    $admin = cfwAdmin();
    $counsellor = cfwCounsellor();
    $recognition = cfwRecognition($counsellor);
    cfwStore($this, $counsellor, ['counsellor_recognition_id' => $recognition->id], $admin)
        ->assertOk()
        ->assertJsonPath('waiver.recognitionDecision', 'approved')
        ->assertJsonPath('waiver.needsReview', false);

    CounsellorRecognitionService::new()->skip(CounsellorRecognitionDTO::new()->fromArray([
        'user' => $admin, 'month' => '2026-10', 'counsellorId' => $counsellor->id,
    ]));

    $this->actingAs($admin)->getJson(route('admin.counsellors.fee-waivers.index', ['counsellor' => $counsellor->id]))
        ->assertJsonPath('counsellor.eligible', true)
        ->assertJsonPath('waivers.0.recognitionId', $recognition->id)
        ->assertJsonPath('waivers.0.recognitionDecision', 'skipped')
        ->assertJsonPath('waivers.0.needsReview', true);

    expect(PlatformFeeWaiver::query()->sole()->status())->toBe(PlatformFeeWaiver::UPCOMING);
});

test('a period of a counsellor reported afterwards is flagged, and not ended', function () {
    $admin = cfwAdmin();
    $counsellor = cfwCounsellor();
    $running = cfwWaiver($counsellor, '2026-11-19 00:00', '2026-11-25 00:00');
    cfwOpenReport($counsellor);

    $this->actingAs($admin)->getJson(route('admin.counsellors.fee-waivers.index', ['counsellor' => $counsellor->id]))
        ->assertJsonPath('counsellor.eligible', false)
        ->assertJsonPath('waivers.0.needsReview', true);

    expect($running->fresh()->status())->toBe(PlatformFeeWaiver::ACTIVE);
});

// ---- Append-only, in the database too ----

test('the period history refuses changes and deletes in the database', function () {
    cfwStore($this, cfwCounsellor())->assertOk();
    $id = PlatformFeeWaiverEvent::query()->sole()->id;

    expect(fn () => PlatformFeeWaiverEvent::query()->whereKey($id)->update(['event' => 'ended']))->toThrow(QueryException::class)
        ->and(fn () => PlatformFeeWaiverEvent::query()->whereKey($id)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('platform_fee_waiver_events')->where('id', $id)->update(['data' => null]))->toThrow(QueryException::class)
        ->and(PlatformFeeWaiverEvent::query()->sole()->event)->toBe(PlatformFeeWaiverEventEnum::created);
})->group('mysql-triggers');

test('the recognition history refuses changes and deletes in the database', function () {
    $recognition = cfwRecognition(cfwCounsellor());
    $event = RecordCounsellorRecognitionEventAction::new()->execute($recognition, CounsellorRecognitionEventEnum::approved, cfwAdmin());

    expect(fn () => CounsellorRecognitionEvent::query()->whereKey($event->id)->update(['event' => 'skipped']))->toThrow(QueryException::class)
        ->and(fn () => CounsellorRecognitionEvent::query()->whereKey($event->id)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('counsellor_recognition_events')->where('id', $event->id)->delete())->toThrow(QueryException::class)
        ->and(CounsellorRecognitionEvent::query()->sole()->event)->toBe(CounsellorRecognitionEventEnum::approved);
})->group('mysql-triggers');

// ---- Purged counsellors ----

test('purging a counsellor records a deleted event for each of their periods first', function () {
    config(['counsellor.deletion_grace_period_days' => 60]);
    $counsellor = cfwCounsellor();
    $ended = cfwWaiver($counsellor, '2026-10-01 00:00', '2026-10-08 00:00', ['reason' => 'Old']);
    $upcoming = cfwWaiver($counsellor, '2026-12-01 00:00', '2027-01-01 00:00', ['reason' => 'New']);
    $kept = cfwWaiver(cfwCounsellor(), '2026-12-01 00:00', '2027-01-01 00:00');
    $counsellor->delete();
    $counsellor->forceFill(['deleted_at' => now()->subDays(61)])->save();

    PurgeExpiredSoftDeletedCounsellorsAction::new()->execute();

    expect(Counsellor::withTrashed()->find($counsellor->id))->toBeNull()
        ->and(PlatformFeeWaiver::query()->pluck('id')->all())->toBe([$kept->id]);

    $events = PlatformFeeWaiverEvent::query()->orderBy('id')->get();
    expect($events->pluck('platform_fee_waiver_id')->all())->toBe([$ended->id, $upcoming->id])
        ->and($events->pluck('event')->unique()->all())->toBe([PlatformFeeWaiverEventEnum::deleted])
        ->and($events->pluck('user_id')->unique()->all())->toBe([null])
        ->and($events->pluck('counsellor_id')->unique()->all())->toBe([$counsellor->id])
        ->and($events[1]->data)->toBe(['starts_at' => '2026-12-01T00:00:00Z', 'ends_at' => '2027-01-01T00:00:00Z', 'reason' => 'New', 'purged' => true]);
});

// ---- The notice ----

test('a counsellor sees their own period on their payouts and dashboard; others and organisations don\'t', function () {
    $counsellor = cfwCounsellor();
    $other = cfwCounsellor();
    cfwWaiver($counsellor, '2026-12-01 00:00', '2027-01-01 00:00', ['reason' => 'Secret reason']);
    $personal = ['startsAt' => '2026-12-01T00:00:00Z', 'endsAt' => '2027-01-01T00:00:00Z', 'scope' => 'personal'];

    expect(GetCounsellorPayoutOverviewAction::new()->execute($counsellor)['commissionFreeNotice'])->toBe($personal)
        ->and(GetCounsellorPayoutOverviewAction::new()->execute($other)['commissionFreeNotice'])->toBeNull()
        ->and(PlatformFeeWaiverService::new()->getCommissionFreeNotice())->toBeNull();

    $this->actingAs($counsellor->user)->get(route('counsellor.dashboard'))
        ->assertInertia(fn ($page) => $page->where('dashboard.earnings.commissionFreeNotice', $personal));
    $this->actingAs($other->user)->get(route('counsellor.dashboard'))
        ->assertInertia(fn ($page) => $page->where('dashboard.earnings.commissionFreeNotice', null));

    $organization = Organization::factory()->create(['is_consumer' => true, 'verified_at' => now()]);
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
    $this->actingAs($owner)->get(route('organizations.reconciliation', ['organizationId' => $organization->id]))
        ->assertInertia(fn ($page) => $page->where('commissionFreeNotice', null));
});

test('the notice prefers a period in force, and the platform-wide one when both are', function () {
    $counsellor = cfwCounsellor();
    $notice = fn () => PlatformFeeWaiverService::new()->getCommissionFreeNotice($counsellor);

    // Beyond the 30-day horizon: nothing yet.
    cfwWaiver($counsellor, '2027-01-01 00:00', '2027-02-01 00:00');
    expect($notice())->toBeNull();

    // A personal one coming up.
    cfwWaiver($counsellor, '2026-12-01 00:00', '2027-01-01 00:00');
    expect($notice()['scope'])->toBe('personal');

    // A platform-wide one coming up sooner.
    cfwWaiver(null, '2026-11-25 00:00', '2026-11-27 00:00');
    expect($notice())->toBe(['startsAt' => '2026-11-25T00:00:00Z', 'endsAt' => '2026-11-27T00:00:00Z', 'scope' => 'platform']);

    // A personal one in force now comes before an upcoming platform-wide one.
    $running = cfwWaiver($counsellor, '2026-11-19 00:00', '2026-11-21 00:00');
    expect($notice())->toBe(['startsAt' => '2026-11-19T00:00:00Z', 'endsAt' => '2026-11-21T00:00:00Z', 'scope' => 'personal']);

    // Both in force: the platform-wide one wins.
    cfwWaiver(null, '2026-11-20 00:00', '2026-11-21 00:00');
    expect($notice()['scope'])->toBe('platform');

    // An ended (cancelled) one never shows.
    PlatformFeeWaiver::query()->whereNull('counsellor_id')->delete();
    $running->forceFill(['ends_at' => now(), 'ended_by_id' => cfwAdmin()->id])->save();
    expect($notice())->toBe(['startsAt' => '2026-12-01T00:00:00Z', 'endsAt' => '2027-01-01T00:00:00Z', 'scope' => 'personal']);
});

// ---- The notification ----

test('the counsellor is told the dates only, under the recognition kind, after commit', function () {
    $admin = cfwAdmin();
    $counsellor = cfwCounsellor();

    cfwStore($this, $counsellor, ['reason' => 'Secret reason'], $admin)->assertOk();

    expect(NotificationCategories::for(new CounsellorFeeWaiverGrantedNotification(PlatformFeeWaiver::query()->sole())))->toBe(NotificationCategoryEnum::recognition);

    Notification::assertSentTo($counsellor, CounsellorFeeWaiverGrantedNotification::class, function ($notification, array $channels, $notifiable) use ($admin) {
        $data = $notification->toArray($notifiable);
        $mail = $notification->toMail($notifiable);
        $text = json_encode([$data, $mail->subject, $mail->introLines, $mail->outroLines]);
        $entry = CounsellorFeeWaiverGrantedNotification::present($data);

        return $notification->afterCommit === true
            && $channels === ['database', 'mail']
            && $data === ['type' => 'fee_waiver.granted', 'startsAt' => '2026-12-01T00:00:00Z', 'endsAt' => '2027-01-01T00:00:00Z']
            && str_contains($mail->introLines[0], 'No platform commission on your payments between 1 Dec 2026, 00:00 GMT and 1 Jan 2027, 00:00 GMT')
            && $entry->message === 'No platform commission on your payments between 1 Dec 2026, 00:00 GMT and 1 Jan 2027, 00:00 GMT.'
            && $entry->url === route('counsellor.dashboard')
            && ! str_contains($text, 'Secret') && ! str_contains($text, $admin->name);
    });
});

test('a refused period tells nobody', function () {
    $counsellor = cfwCounsellor();
    cfwWaiver($counsellor, '2026-12-01 00:00', '2027-01-01 00:00');

    cfwStore($this, $counsellor)->assertUnprocessable();

    Notification::assertNothingSent();
});

test('the notification reads an old or broken entry without failing', function () {
    expect(CounsellorFeeWaiverGrantedNotification::present([])->message)->toBe('No platform commission on your payments for a period.')
        ->and(CounsellorFeeWaiverGrantedNotification::present(['startsAt' => 'nonsense', 'endsAt' => null])->message)->toBe('No platform commission on your payments for a period.');
});

// ---- The money: payments inside and outside the period, at both ends ----

function cfwTherapy(Counsellor $counsellor, User $client): Therapy
{
    return Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $client->id, 'counsellor_id' => $counsellor->id,
        'session_type' => TherapySessionTypeEnum::once->value, 'status' => TherapyStatusEnum::in_session->value,
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => TherapyPerPaymentEnum::therapy->value, 'amount' => 100, 'currency' => 'GHS'],
    ]);
}

// A personal payment made and recorded at this moment.
function cfwPersonalPayment($for, int $amount): Transaction
{
    $transaction = Transaction::factory()->create([
        'for_type' => $for::class, 'for_id' => $for->id, 'amount' => $amount, 'currency' => 'GHS',
        'status' => TransactionStatusEnum::success->value, 'created_at' => now(),
    ]);
    GenerateCounsellorEarningsAction::new()->execute($transaction);

    return $transaction;
}

// An organisation with a card, paying each counsellor 80% of their part of the listed rate.
function cfwOrganization(iterable $counsellors): Organization
{
    $organization = Organization::factory()->create(['is_consumer' => true, 'verified_at' => now()]);
    $organization->admins()->attach(User::factory()->create()->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
    OrganizationPaymentInstrument::factory()->create(['organization_id' => $organization->id, 'currency' => 'GHS']);

    foreach ($counsellors as $counsellor) {
        $affiliation = OrganizationCounsellor::factory()->create([
            'organization_id' => $organization->id, 'counsellor_id' => $counsellor->id, 'status' => OrganizationCounsellorStatusEnum::active->value,
        ]);
        OrganizationCounsellorCompensation::factory()->create([
            'organization_counsellor_id' => $affiliation->id, 'type' => OrganizationCounsellorCompensationTypeEnum::percentage->value,
            'basis' => OrganizationCounsellorCompensationBasisEnum::counsellorRate->value, 'percentage' => 80,
        ]);
    }

    return $organization;
}

// The organisation's card charged for $member's place in $for, at this moment.
function cfwOrgCharge(Organization $organization, User $member, $for, int $expectedAmount): Transaction
{
    Http::fake(['*/transaction/charge_authorization' => Http::response([
        'status' => true,
        'data' => ['status' => 'success', 'amount' => $expectedAmount, 'currency' => 'GHS', 'gateway_response' => 'Approved'],
    ], 200)]);

    return ChargeOrganizationForModelAction::new()->execute(TransactionDTO::new()->fromArray([
        'user' => $member, 'for' => $for, 'organization' => $organization,
    ]));
}

// A paid group (GHS 150) with these counsellors, split equally.
function cfwGroup(array $counsellors, ?User $creator = null): GroupTherapy
{
    $group = GroupTherapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => ($creator ?? User::factory()->adult()->create())->id,
        'session_type' => TherapySessionTypeEnum::once->value, 'status' => TherapyStatusEnum::in_session->value,
        'payment_type' => TherapyPaymentTypeEnum::paid->value,
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 150, 'currency' => 'GHS', 'shareEqually' => true], 'max_users' => 10,
    ]);
    foreach ($counsellors as $counsellor) {
        $group->counsellors()->attach($counsellor->id, ['state' => CounsellorGroupTherapyStateEnum::active->value, 'role' => 'NORMAL']);
    }

    return $group->fresh();
}

dataset('cfw boundaries', [
    'a second before the start' => ['2026-11-30 23:59:59', false],
    'at the start' => ['2026-12-01 00:00:00', true],
    'a second before the end' => ['2026-12-31 23:59:59', true],
    'at the end' => ['2027-01-01 00:00:00', false],
]);

test('a personal payment pays no fee only inside the counsellor\'s period', function (string $at, bool $waived) {
    $counsellor = cfwCounsellor();
    $waiver = cfwWaiver($counsellor, '2026-12-01 00:00', '2027-01-01 00:00');
    $this->travelTo(Carbon::parse($at, 'UTC'));

    $earning = cfwPersonalPayment(cfwTherapy($counsellor, User::factory()->adult()->create()), 10000)->earnings()->sole();

    expect($earning->only('fee_amount', 'net_amount', 'platform_fee_waiver_id', 'waived_fee_amount'))->toBe($waived
        ? ['fee_amount' => 0, 'net_amount' => 10000, 'platform_fee_waiver_id' => $waiver->id, 'waived_fee_amount' => 1000]
        : ['fee_amount' => 1000, 'net_amount' => 9000, 'platform_fee_waiver_id' => null, 'waived_fee_amount' => 0]);
})->with('cfw boundaries');

test('an organisation charge adds no fee only inside the counsellor\'s period', function (string $at, bool $waived) {
    $counsellor = cfwCounsellor();
    $waiver = cfwWaiver($counsellor, '2026-12-01 00:00', '2027-01-01 00:00');
    $this->travelTo(Carbon::parse($at, 'UTC'));

    // Listed 10000, 80% = 8000; the fee would have been 1000.
    $member = User::factory()->adult()->create();
    $transaction = cfwOrgCharge(cfwOrganization([$counsellor]), $member, cfwTherapy($counsellor, $member), $waived ? 8000 : 9000);

    expect($transaction->amount)->toBe($waived ? 8000 : 9000)
        ->and($transaction->earnings()->sole()->only('fee_amount', 'platform_fee_waiver_id', 'waived_fee_amount'))->toBe($waived
            ? ['fee_amount' => 0, 'platform_fee_waiver_id' => $waiver->id, 'waived_fee_amount' => 1000]
            : ['fee_amount' => 1000, 'platform_fee_waiver_id' => null, 'waived_fee_amount' => 0]);
})->with('cfw boundaries');

test('a counsellor\'s period covers only them, and the platform-wide id is recorded when both apply', function () {
    $counsellor = cfwCounsellor();
    $other = cfwCounsellor();
    cfwStore($this, $counsellor, ['starts_at' => '2026-11-20T12:00', 'ends_at' => '2026-12-20T00:00'])->assertOk();
    $personal = PlatformFeeWaiver::query()->sole();

    $mine = cfwPersonalPayment(cfwTherapy($counsellor, User::factory()->adult()->create()), 10000)->earnings()->sole();
    $theirs = cfwPersonalPayment(cfwTherapy($other, User::factory()->adult()->create()), 10000)->earnings()->sole();
    expect($mine->platform_fee_waiver_id)->toBe($personal->id)->and($mine->fee_amount)->toBe(0)
        ->and($theirs->platform_fee_waiver_id)->toBeNull()->and($theirs->fee_amount)->toBe(1000);

    $platform = cfwWaiver(null, '2026-11-20 00:00', '2026-11-30 00:00');
    $both = cfwPersonalPayment(cfwTherapy($counsellor, User::factory()->adult()->create()), 10000)->earnings()->sole();
    expect($both->only('fee_amount', 'net_amount', 'platform_fee_waiver_id', 'waived_fee_amount'))
        ->toBe(['fee_amount' => 0, 'net_amount' => 10000, 'platform_fee_waiver_id' => $platform->id, 'waived_fee_amount' => 1000]);

    // A group payment: the period frees only its counsellor's share.
    $group = cfwGroup([$counsellor, $other]);
    $platform->forceFill(['ends_at' => now()])->save();
    $this->travel(1)->second();

    $earnings = cfwPersonalPayment($group->fresh(), 10000)->earnings()->get()->keyBy('counsellor_id');
    expect($earnings[$counsellor->id]->only('fee_amount', 'platform_fee_waiver_id'))->toBe(['fee_amount' => 0, 'platform_fee_waiver_id' => $personal->id])
        ->and($earnings[$other->id]->only('fee_amount', 'platform_fee_waiver_id'))->toBe(['fee_amount' => 500, 'platform_fee_waiver_id' => null]);
});

test('a running counsellor period can\'t be deleted, only ended', function () {
    $counsellor = cfwCounsellor();
    $waiver = cfwWaiver($counsellor, '2026-11-19 00:00', '2026-12-01 00:00');
    cfwPersonalPayment(cfwTherapy($counsellor, User::factory()->adult()->create()), 10000);

    try {
        PlatformFeeWaiverService::new()->deleteForCounsellor(cfwDto(cfwAdmin(), $counsellor->id, $waiver->id));
        $this->fail('The running period was deleted.');
    } catch (ValidationException $e) {
        expect($e->errors()['waiver'][0])->toContain('has not started');
    }

    PlatformFeeWaiverService::new()->endNowForCounsellor(cfwDto(cfwAdmin(), $counsellor->id, $waiver->id));
    expect($waiver->fresh()->status())->toBe(PlatformFeeWaiver::CANCELLED);
});

test('an upcoming counsellor period a payment refers to can\'t be deleted', function () {
    $counsellor = cfwCounsellor();
    $waiver = cfwWaiver($counsellor, '2026-12-01 00:00', '2027-01-01 00:00');
    // Can't happen through the fee policy (it only waives under a period in force); forced here to reach the guard.
    cfwPersonalPayment(cfwTherapy($counsellor, User::factory()->adult()->create()), 10000)
        ->earnings()->sole()->forceFill(['platform_fee_waiver_id' => $waiver->id])->save();

    try {
        PlatformFeeWaiverService::new()->deleteForCounsellor(cfwDto(cfwAdmin(), $counsellor->id, $waiver->id));
        $this->fail('The referenced period was deleted.');
    } catch (ValidationException $e) {
        expect($e->errors()['waiver'][0])->toContain('used by a payment');
    }

    expect($waiver->fresh())->not->toBeNull()->and(PlatformFeeWaiverEvent::query()->count())->toBe(0);
});

test('a retainer line pays no fee only when recorded inside the counsellor\'s period', function (string $at, bool $waived) {
    Bus::fake();
    $counsellor = cfwCounsellor();
    $waiver = cfwWaiver($counsellor, '2026-12-01 00:00', '2027-01-01 00:00');
    $organization = cfwOrganization([$counsellor]);
    $member = User::factory()->create();
    $membership = OrganizationMember::factory()->create(['organization_id' => $organization->id, 'user_id' => $member->id]);
    OrganizationMemberBillingConfig::factory()->create([
        'organization_member_id' => $membership->id, 'mode' => OrganizationMemberBillingModeEnum::retainer->value,
    ]);
    $therapy = cfwTherapy($counsellor, $member);
    $this->travelTo(Carbon::parse($at, 'UTC'));

    RecordOrganizationInvoiceLineForSessionAction::new()->execute(Session::factory()->create(['for_id' => $therapy->id, 'for_type' => Therapy::class]));

    expect(OrganizationInvoiceLine::query()->sole()->only('net_amount', 'fee_amount', 'platform_fee_waiver_id', 'waived_fee_amount'))->toBe($waived
        ? ['net_amount' => 8000, 'fee_amount' => 0, 'platform_fee_waiver_id' => $waiver->id, 'waived_fee_amount' => 1000]
        : ['net_amount' => 8000, 'fee_amount' => 1000, 'platform_fee_waiver_id' => null, 'waived_fee_amount' => 0]);
})->with('cfw boundaries');

test('an organisation charge for a group place waives only the covered counsellor\'s part', function () {
    $covered = cfwCounsellor();
    $other = cfwCounsellor();
    $waiver = cfwWaiver($covered, '2026-11-19 00:00', '2026-12-01 00:00');
    $member = User::factory()->adult()->create();
    $group = cfwGroup([$covered, $other], $member);

    // 15000 split 7500/7500, 80% each = 6000 + 6000, plus the other counsellor's fee (750) on top.
    $transaction = cfwOrgCharge(cfwOrganization([$covered, $other]), $member, $group, 12750);

    $earnings = $transaction->earnings()->get()->keyBy('counsellor_id');
    expect($transaction->amount)->toBe(12750)
        ->and($earnings[$covered->id]->only('fee_amount', 'platform_fee_waiver_id', 'waived_fee_amount'))
        ->toBe(['fee_amount' => 0, 'platform_fee_waiver_id' => $waiver->id, 'waived_fee_amount' => 750])
        ->and($earnings[$other->id]->only('fee_amount', 'platform_fee_waiver_id', 'waived_fee_amount'))
        ->toBe(['fee_amount' => 750, 'platform_fee_waiver_id' => null, 'waived_fee_amount' => 0])
        ->and($earnings->sum('net_amount') + $earnings->sum('fee_amount'))->toBe($transaction->amount);
});

test('the service answers not found for a waiver outside its scope', function () {
    $counsellor = cfwCounsellor();
    $platform = cfwWaiver(null, '2026-11-19 00:00', '2026-12-01 00:00');

    expect(fn () => PlatformFeeWaiverService::new()->endNowForCounsellor(cfwDto(cfwAdmin(), $counsellor->id, $platform->id)))
        ->toThrow(PlatformFeeWaiverException::class, PlatformFeeWaiverService::NOT_FOUND);
});
