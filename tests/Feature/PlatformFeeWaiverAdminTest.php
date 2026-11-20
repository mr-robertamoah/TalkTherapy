<?php

use App\Actions\Payout\GetCounsellorPayoutOverviewAction;
use App\DTOs\PlatformFeeWaiverDTO;
use App\Enums\AdministratorTypeEnum;
use App\Enums\OrganizationAdminRoleEnum;
use App\Exceptions\UserException;
use App\Http\Resources\CounsellorEarningResource;
use App\Http\Resources\OrganizationFinancedTransactionResource;
use App\Http\Resources\OrganizationInvoiceLineResource;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\CounsellorEarning;
use App\Models\Organization;
use App\Models\OrganizationInvoice;
use App\Models\OrganizationInvoiceLine;
use App\Models\PlatformFeeWaiver;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PlatformFeeWaiverService;
use Database\Seeders\CommissionFreePeriodSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

// TT-7.10b/SCRUM-468: platform-wide commission-free periods -- every admin sees them, only a super admin creates,
// ends or deletes one; counsellors and organisations see labels on waived money and a notice with the dates only.

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-20 12:00:00', 'UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function pfwaAdmin(bool $super = true): User
{
    return User::factory()
        ->has(Administrator::factory()->state([
            'type' => $super ? AdministratorTypeEnum::super->value : AdministratorTypeEnum::normal->value,
        ]))
        ->create();
}

function pfwaValid(array $overrides = []): array
{
    return array_merge([
        'starts_at' => '2026-12-01T00:00',
        'ends_at' => '2026-12-08T00:00',
        'reason' => 'Awareness week',
    ], $overrides);
}

function pfwaWaiver(string $startsAt, ?string $endsAt, array $attributes = []): PlatformFeeWaiver
{
    return PlatformFeeWaiver::factory()
        ->between(Carbon::parse($startsAt, 'UTC'), $endsAt ? Carbon::parse($endsAt, 'UTC') : null)
        ->create($attributes);
}

function pfwaTransaction(array $attributes = []): Transaction
{
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);

    return Transaction::factory()->create(array_merge(['for_type' => Therapy::class, 'for_id' => $therapy->id], $attributes));
}

// ---- Viewing ----

test('any admin sees the platform-wide periods with their status, and only a super admin may change them', function () {
    pfwaWaiver('2026-12-01 00:00', '2026-12-08 00:00', ['reason' => 'Coming up']);
    pfwaWaiver('2026-11-19 00:00', '2026-11-25 00:00', ['reason' => 'Running']);
    pfwaWaiver('2026-10-01 00:00', '2026-10-08 00:00', ['reason' => 'Finished']);
    pfwaWaiver('2026-09-01 00:00', '2026-09-08 00:00', ['reason' => 'Stopped early'])->forceFill(['ended_by_id' => pfwaAdmin()->id])->save();
    PlatformFeeWaiver::factory()->forCounsellor(Counsellor::factory()->create(['user_id' => User::factory()]))->create(['reason' => 'A counsellor\'s own']);

    $this->actingAs(pfwaAdmin(super: false))->get(route('administrator.payouts'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Payouts')
            ->where('canManageFeeWaivers', false)
            ->has('feeWaivers', 4)
            ->where('feeWaivers.0.reason', 'Coming up')
            ->where('feeWaivers.0.status', 'upcoming')
            ->where('feeWaivers.0.canDelete', false)
            ->where('feeWaivers.1.status', 'active')
            ->where('feeWaivers.1.canEnd', false)
            ->where('feeWaivers.2.status', 'ended')
            ->where('feeWaivers.3.status', 'cancelled')
        );

    $this->actingAs(pfwaAdmin())->get(route('administrator.payouts'))
        ->assertInertia(fn ($page) => $page
            ->where('canManageFeeWaivers', true)
            ->where('feeWaivers.0.canDelete', true)
            ->where('feeWaivers.0.canEnd', false)
            ->where('feeWaivers.1.canEnd', true)
            ->where('feeWaivers.1.canDelete', false)
            ->where('feeWaivers.2.canEnd', false)
            ->where('feeWaivers.2.canDelete', false)
        );
});

test('a period\'s status follows its half-open times and whether an admin ended it', function () {
    $waiver = pfwaWaiver('2026-12-01 00:00', '2026-12-08 00:00');

    expect($waiver->status(Carbon::parse('2026-11-30 23:59:59', 'UTC')))->toBe(PlatformFeeWaiver::UPCOMING)
        ->and($waiver->status(Carbon::parse('2026-12-01 00:00', 'UTC')))->toBe(PlatformFeeWaiver::ACTIVE)
        ->and($waiver->status(Carbon::parse('2026-12-07 23:59:59', 'UTC')))->toBe(PlatformFeeWaiver::ACTIVE)
        ->and($waiver->status(Carbon::parse('2026-12-08 00:00', 'UTC')))->toBe(PlatformFeeWaiver::ENDED)
        ->and(pfwaWaiver('2026-11-01 00:00', null)->status())->toBe(PlatformFeeWaiver::ACTIVE);

    $waiver->forceFill(['ended_by_id' => pfwaAdmin()->id, 'ends_at' => Carbon::parse('2026-12-03 00:00', 'UTC')])->save();

    expect($waiver->status(Carbon::parse('2026-12-10 00:00', 'UTC')))->toBe(PlatformFeeWaiver::CANCELLED);
});

// ---- Creating ----

test('a super admin creates a platform-wide period, in UTC, recorded as theirs', function () {
    $admin = pfwaAdmin();

    $this->actingAs($admin)->post(route('admin.settings.platform-fee-waivers.store'), pfwaValid(['reason' => '  Awareness week  ']))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $waiver = PlatformFeeWaiver::query()->sole();
    expect($waiver->counsellor_id)->toBeNull()
        ->and($waiver->created_by_id)->toBe($admin->id)
        ->and($waiver->ended_by_id)->toBeNull()
        ->and($waiver->reason)->toBe('Awareness week')
        ->and($waiver->starts_at->toDateTimeString())->toBe('2026-12-01 00:00:00')
        ->and($waiver->ends_at->toDateTimeString())->toBe('2026-12-08 00:00:00');
});

test('a time sent with an offset is stored in UTC', function () {
    $this->actingAs(pfwaAdmin())->post(route('admin.settings.platform-fee-waivers.store'), pfwaValid([
        'starts_at' => '2026-12-01T02:00:00+02:00',
        'ends_at' => '2026-12-08T02:00:00+02:00',
    ]))->assertSessionHasNoErrors();

    expect(PlatformFeeWaiver::query()->sole()->starts_at->toDateTimeString())->toBe('2026-12-01 00:00:00');
});

test('created_by_id and ended_by_id can\'t be sent in', function () {
    $other = pfwaAdmin();

    $this->actingAs(pfwaAdmin())->post(route('admin.settings.platform-fee-waivers.store'), pfwaValid([
        'created_by_id' => $other->id,
        'ended_by_id' => $other->id,
    ]))->assertSessionHasNoErrors();

    $waiver = PlatformFeeWaiver::query()->sole();
    expect($waiver->created_by_id)->not->toBe($other->id)
        ->and($waiver->ended_by_id)->toBeNull();
});

test('a start within the last minute is allowed and moved up to now; one further back is backdating', function () {
    $admin = pfwaAdmin();

    $this->actingAs($admin)->post(route('admin.settings.platform-fee-waivers.store'), pfwaValid([
        'starts_at' => '2026-11-20T11:58:30',
    ]))->assertSessionHasErrors('starts_at');

    $this->actingAs($admin)->post(route('admin.settings.platform-fee-waivers.store'), pfwaValid([
        'starts_at' => '2026-11-20T11:59:30',
    ]))->assertSessionHasNoErrors();

    expect(PlatformFeeWaiver::query()->sole()->starts_at->toDateTimeString())->toBe('2026-11-20 12:00:00');
});

test('an end in the past is refused, even when the start is within the minute\'s leeway', function () {
    // Both within the leeway: the start would be moved up to now, past the end.
    $this->actingAs(pfwaAdmin())->post(route('admin.settings.platform-fee-waivers.store'), pfwaValid([
        'starts_at' => '2026-11-20T11:59:20',
        'ends_at' => '2026-11-20T11:59:40',
    ]))->assertSessionHasErrors('ends_at');

    expect(PlatformFeeWaiver::query()->count())->toBe(0);
});

test('the service refuses an end that isn\'t after the start, whoever calls it', function (string $endsAt) {
    $dto = PlatformFeeWaiverDTO::new()->fromArray(['user' => pfwaAdmin()]);

    try {
        PlatformFeeWaiverService::new()->create($dto, [
            'starts_at' => Carbon::parse('2026-11-20 12:00:00', 'UTC'),
            'ends_at' => Carbon::parse($endsAt, 'UTC'),
            'reason' => 'x',
        ]);
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('ends_at');
    }

    expect(PlatformFeeWaiver::query()->count())->toBe(0);
})->with([
    'before the start' => ['2026-11-20 11:59:40'],
    'equal to the start' => ['2026-11-20 12:00:00'],
]);

test('the end is required and must be after the start', function (?string $endsAt) {
    $this->actingAs(pfwaAdmin())->post(route('admin.settings.platform-fee-waivers.store'), pfwaValid(['ends_at' => $endsAt]))
        ->assertSessionHasErrors('ends_at');

    expect(PlatformFeeWaiver::query()->count())->toBe(0);
})->with([
    'missing' => [null],
    'equal to the start' => ['2026-12-01T00:00'],
    'before the start' => ['2026-11-30T00:00'],
]);

test('a reason is required and limited in length', function (?string $reason) {
    $this->actingAs(pfwaAdmin())->post(route('admin.settings.platform-fee-waivers.store'), pfwaValid(['reason' => $reason]))
        ->assertSessionHasErrors('reason');
})->with([
    'missing' => [null],
    'too long' => [str_repeat('a', 501)],
]);

test('a period more than two years ahead is refused', function () {
    $this->actingAs(pfwaAdmin())->post(route('admin.settings.platform-fee-waivers.store'), pfwaValid([
        'starts_at' => '2026-12-01T00:00',
        'ends_at' => '2029-01-01T00:00',
    ]))->assertSessionHasErrors('ends_at');
});

test('a counsellor id is refused (only platform-wide periods here); null or absent is fine', function () {
    $admin = pfwaAdmin();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);

    $this->actingAs($admin)->post(route('admin.settings.platform-fee-waivers.store'), pfwaValid(['counsellor_id' => $counsellor->id]))
        ->assertSessionHasErrors('counsellor_id');
    expect(PlatformFeeWaiver::query()->count())->toBe(0);

    $this->actingAs($admin)->post(route('admin.settings.platform-fee-waivers.store'), pfwaValid(['counsellor_id' => null]))
        ->assertSessionHasNoErrors();
    expect(PlatformFeeWaiver::query()->sole()->counsellor_id)->toBeNull();
});

test('an overlapping platform-wide period is refused', function (string $startsAt, string $endsAt) {
    pfwaWaiver('2026-12-10 00:00', '2026-12-20 00:00');

    $this->actingAs(pfwaAdmin())->post(route('admin.settings.platform-fee-waivers.store'), pfwaValid(['starts_at' => $startsAt, 'ends_at' => $endsAt]))
        ->assertSessionHasErrors('starts_at');

    expect(PlatformFeeWaiver::query()->count())->toBe(1);
})->with([
    'starting inside it' => ['2026-12-15T00:00', '2026-12-25T00:00'],
    'ending inside it' => ['2026-12-05T00:00', '2026-12-10T00:01'],
    'inside it' => ['2026-12-12T00:00', '2026-12-13T00:00'],
    'around it' => ['2026-12-01T00:00', '2026-12-31T00:00'],
    'the same' => ['2026-12-10T00:00', '2026-12-20T00:00'],
]);

test('periods that only touch (one ends as the next starts) don\'t overlap', function (string $startsAt, string $endsAt) {
    pfwaWaiver('2026-12-10 00:00', '2026-12-20 00:00');

    $this->actingAs(pfwaAdmin())->post(route('admin.settings.platform-fee-waivers.store'), pfwaValid(['starts_at' => $startsAt, 'ends_at' => $endsAt]))
        ->assertSessionHasNoErrors();

    expect(PlatformFeeWaiver::query()->count())->toBe(2);
})->with([
    'just before' => ['2026-12-01T00:00', '2026-12-10T00:00'],
    'just after' => ['2026-12-20T00:00', '2026-12-25T00:00'],
]);

test('an open-ended period overlaps anything after its start; a cancelled one only up to when it was ended', function () {
    $admin = pfwaAdmin();
    pfwaWaiver('2026-11-01 00:00', null);

    $this->actingAs($admin)->post(route('admin.settings.platform-fee-waivers.store'), pfwaValid())
        ->assertSessionHasErrors('starts_at');

    PlatformFeeWaiver::query()->update(['ends_at' => Carbon::parse('2026-11-20 12:00', 'UTC'), 'ended_by_id' => $admin->id]);

    $this->actingAs($admin)->post(route('admin.settings.platform-fee-waivers.store'), pfwaValid())
        ->assertSessionHasNoErrors();
});

test('a counsellor\'s own waiver doesn\'t block a platform-wide period', function () {
    PlatformFeeWaiver::factory()->forCounsellor(Counsellor::factory()->create(['user_id' => User::factory()]))
        ->between(Carbon::parse('2026-12-01', 'UTC'), Carbon::parse('2026-12-31', 'UTC'))->create();

    $this->actingAs(pfwaAdmin())->post(route('admin.settings.platform-fee-waivers.store'), pfwaValid())
        ->assertSessionHasNoErrors();

    expect(PlatformFeeWaiver::query()->platformWide()->count())->toBe(1);
});

// ---- Ending ----

test('a super admin ends a running period now, recorded as theirs, which then shows as cancelled', function () {
    $admin = pfwaAdmin();
    $waiver = pfwaWaiver('2026-11-19 00:00', '2026-11-25 00:00');

    $this->actingAs($admin)->post(route('admin.settings.platform-fee-waivers.end', ['waiver' => $waiver->id]))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $waiver->refresh();
    expect($waiver->ends_at->toDateTimeString())->toBe('2026-11-20 12:00:00')
        ->and($waiver->ended_by_id)->toBe($admin->id)
        ->and($waiver->starts_at->toDateTimeString())->toBe('2026-11-19 00:00:00')
        ->and($waiver->status())->toBe(PlatformFeeWaiver::CANCELLED);
});

test('"End now" is refused on a period that is not running', function (string $startsAt, string $endsAt, bool $cancelled) {
    $waiver = pfwaWaiver($startsAt, $endsAt);
    if ($cancelled) {
        $waiver->forceFill(['ended_by_id' => pfwaAdmin()->id])->save();
    }
    $before = $waiver->fresh()->ends_at->toDateTimeString();

    $this->actingAs(pfwaAdmin())->post(route('admin.settings.platform-fee-waivers.end', ['waiver' => $waiver->id]))
        ->assertSessionHasErrors('waiver');

    expect($waiver->fresh()->ends_at->toDateTimeString())->toBe($before);
})->with([
    'upcoming' => ['2026-12-01 00:00', '2026-12-08 00:00', false],
    'ended' => ['2026-10-01 00:00', '2026-10-08 00:00', false],
    'already ended early' => ['2026-11-01 00:00', '2026-11-10 00:00', true],
]);

test('a counsellor\'s own waiver is not ended or deleted here', function () {
    $waiver = PlatformFeeWaiver::factory()->forCounsellor(Counsellor::factory()->create(['user_id' => User::factory()]))->create();
    $admin = pfwaAdmin();

    $this->actingAs($admin)->postJson(route('admin.settings.platform-fee-waivers.end', ['waiver' => $waiver->id]))->assertNotFound();
    $this->actingAs($admin)->postJson(route('admin.settings.platform-fee-waivers.delete', ['waiver' => $waiver->id]))->assertNotFound();

    expect($waiver->fresh()->ends_at)->toBeNull();
});

// ---- Deleting ----

test('a super admin deletes a period that has not started', function () {
    $waiver = pfwaWaiver('2026-12-01 00:00', '2026-12-08 00:00');

    $this->actingAs(pfwaAdmin())->post(route('admin.settings.platform-fee-waivers.delete', ['waiver' => $waiver->id]))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(PlatformFeeWaiver::query()->find($waiver->id))->toBeNull();
});

test('a period that has started is not deleted', function (string $startsAt, string $endsAt) {
    $waiver = pfwaWaiver($startsAt, $endsAt);

    $this->actingAs(pfwaAdmin())->post(route('admin.settings.platform-fee-waivers.delete', ['waiver' => $waiver->id]))
        ->assertSessionHasErrors('waiver');

    expect(PlatformFeeWaiver::query()->find($waiver->id))->not->toBeNull();
})->with([
    'running' => ['2026-11-19 00:00', '2026-11-25 00:00'],
    'ended' => ['2026-10-01 00:00', '2026-10-08 00:00'],
    'starting this very moment' => ['2026-11-20 12:00', '2026-11-25 00:00'],
]);

test('an unstarted period something refers to is not deleted (a field error, not a 500)', function (string $kind) {
    $waiver = pfwaWaiver('2026-12-01 00:00', '2026-12-08 00:00');

    match ($kind) {
        'earning' => CounsellorEarning::factory()->create([
            'transaction_id' => pfwaTransaction()->id,
            'counsellor_id' => Counsellor::factory()->create(['user_id' => User::factory()])->id,
            'platform_fee_waiver_id' => $waiver->id,
        ]),
        'invoice line' => OrganizationInvoiceLine::factory()->create(['platform_fee_waiver_id' => $waiver->id]),
    };

    $this->actingAs(pfwaAdmin())->post(route('admin.settings.platform-fee-waivers.delete', ['waiver' => $waiver->id]))
        ->assertSessionHasErrors('waiver');

    expect(PlatformFeeWaiver::query()->find($waiver->id))->not->toBeNull();
})->with(['earning', 'invoice line']);

test('a period starting within a minute is not deleted, so a delete can\'t race its start', function (string $startsAt, bool $deleted) {
    $waiver = pfwaWaiver($startsAt, '2026-11-25 00:00');

    $response = $this->actingAs(pfwaAdmin())->post(route('admin.settings.platform-fee-waivers.delete', ['waiver' => $waiver->id]));

    if ($deleted) {
        $response->assertSessionHasNoErrors();
        expect(PlatformFeeWaiver::query()->find($waiver->id))->toBeNull();
    } else {
        $response->assertSessionHasErrors('waiver');
        expect(PlatformFeeWaiver::query()->find($waiver->id))->not->toBeNull();
    }
})->with([
    'in 30 seconds' => ['2026-11-20 12:00:30', false],
    'in exactly 60 seconds' => ['2026-11-20 12:01:00', false],
    'in 61 seconds' => ['2026-11-20 12:01:01', true],
]);

test('a database error other than a foreign key refusal is not hidden as a field error', function () {
    $waiver = pfwaWaiver('2026-12-01 00:00', '2026-12-08 00:00');
    PlatformFeeWaiver::deleting(fn () => throw new QueryException('sqlite', 'delete', [], new PDOException('disk I/O error', 0)));

    $dto = PlatformFeeWaiverDTO::new()->fromArray(['user' => pfwaAdmin(), 'waiverId' => $waiver->id]);

    expect(fn () => PlatformFeeWaiverService::new()->delete($dto))->toThrow(QueryException::class);
});

// ---- Who may ----

test('an ordinary admin gets a 403 on every change, and nothing changes', function () {
    $admin = pfwaAdmin(super: false);
    $running = pfwaWaiver('2026-11-19 00:00', '2026-11-25 00:00');
    $upcoming = pfwaWaiver('2026-12-10 00:00', '2026-12-15 00:00');

    $this->actingAs($admin)->post(route('admin.settings.platform-fee-waivers.store'), pfwaValid())->assertForbidden();
    // Refused before validation: an invalid body is still a 403, not a field error.
    $this->actingAs($admin)->post(route('admin.settings.platform-fee-waivers.store'), [])->assertForbidden();
    $this->actingAs($admin)->post(route('admin.settings.platform-fee-waivers.end', ['waiver' => $running->id]))->assertForbidden();
    $this->actingAs($admin)->post(route('admin.settings.platform-fee-waivers.delete', ['waiver' => $upcoming->id]))->assertForbidden();

    expect(PlatformFeeWaiver::query()->count())->toBe(2)
        ->and($running->fresh()->ended_by_id)->toBeNull()
        ->and($running->fresh()->ends_at->toDateTimeString())->toBe('2026-11-25 00:00:00');
});

test('a non-admin is refused at every change', function () {
    $user = User::factory()->create();
    $waiver = pfwaWaiver('2026-11-19 00:00', '2026-11-25 00:00');

    $this->actingAs($user)->postJson(route('admin.settings.platform-fee-waivers.store'), pfwaValid())->assertForbidden();
    $this->actingAs($user)->postJson(route('admin.settings.platform-fee-waivers.end', ['waiver' => $waiver->id]))->assertForbidden();
    $this->actingAs($user)->postJson(route('admin.settings.platform-fee-waivers.delete', ['waiver' => $waiver->id]))->assertForbidden();

    expect(PlatformFeeWaiver::query()->count())->toBe(1);
});

test('the service itself refuses an ordinary admin', function () {
    $dto = PlatformFeeWaiverDTO::new()->fromArray(['user' => pfwaAdmin(super: false)]);

    PlatformFeeWaiverService::new()->create($dto, [
        'starts_at' => Carbon::parse('2026-12-01', 'UTC'),
        'ends_at' => Carbon::parse('2026-12-08', 'UTC'),
        'reason' => 'x',
    ]);
})->throws(UserException::class, PlatformFeeWaiverService::NOT_ALLOWED);

// ---- Labels ----

test('a waived earning is labelled commission-free with what was waived, and says nothing of the period', function () {
    $waiver = pfwaWaiver('2026-11-19 00:00', '2026-11-25 00:00', ['reason' => 'Secret reason']);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $waived = CounsellorEarning::factory()->create([
        'transaction_id' => pfwaTransaction()->id, 'counsellor_id' => $counsellor->id,
        'gross_amount' => 10000, 'fee_amount' => 0, 'net_amount' => 10000,
        'platform_fee_waiver_id' => $waiver->id, 'waived_fee_amount' => 1000,
    ]);
    $ordinary = CounsellorEarning::factory()->create([
        'transaction_id' => pfwaTransaction()->id, 'counsellor_id' => $counsellor->id,
        'gross_amount' => 10000, 'fee_amount' => 1000, 'net_amount' => 9000,
    ]);

    $waivedRow = (new CounsellorEarningResource($waived))->toArray(Request::create('/'));
    $ordinaryRow = (new CounsellorEarningResource($ordinary))->toArray(Request::create('/'));

    expect($waivedRow['commissionFree'])->toBeTrue()
        ->and($waivedRow['waivedFeeAmount'])->toBe(1000)
        ->and($ordinaryRow['commissionFree'])->toBeFalse()
        ->and($ordinaryRow['waivedFeeAmount'])->toBe(0)
        ->and(json_encode($waivedRow))->not->toContain('Secret reason')
        ->and($waivedRow)->not->toHaveKey('platformFeeWaiverId');
});

test('a waived invoice line and a waived card charge are labelled waived with what was waived', function () {
    $waiver = pfwaWaiver('2026-11-19 00:00', '2026-11-25 00:00', ['reason' => 'Secret reason']);
    $line = OrganizationInvoiceLine::factory()->create(['fee_amount' => 0, 'platform_fee_waiver_id' => $waiver->id, 'waived_fee_amount' => 700]);
    $ordinaryLine = OrganizationInvoiceLine::factory()->create(['fee_amount' => 700]);
    $charge = pfwaTransaction(['breakdown' => ['lines' => [
        ['counsellor_id' => 1, 'net_amount' => 5000, 'fee_amount' => 0, 'platform_fee_waiver_id' => $waiver->id, 'waived_fee_amount' => 600],
        ['counsellor_id' => 2, 'net_amount' => 5000, 'fee_amount' => 0, 'platform_fee_waiver_id' => $waiver->id, 'waived_fee_amount' => 400],
    ]]]);
    $oldCharge = pfwaTransaction(['breakdown' => null]);

    $request = Request::create('/');
    $lineRow = (new OrganizationInvoiceLineResource($line))->toArray($request);
    $ordinaryLineRow = (new OrganizationInvoiceLineResource($ordinaryLine))->toArray($request);
    $chargeRow = (new OrganizationFinancedTransactionResource($charge))->toArray($request);
    $oldChargeRow = (new OrganizationFinancedTransactionResource($oldCharge))->toArray($request);

    expect($lineRow['feeWaived'])->toBeTrue()
        ->and($lineRow['waivedFeeAmount'])->toBe(700)
        ->and($ordinaryLineRow['feeWaived'])->toBeFalse()
        ->and($ordinaryLineRow['waivedFeeAmount'])->toBe(0)
        ->and($chargeRow['feeWaived'])->toBeTrue()
        ->and($chargeRow['waivedFeeAmount'])->toBe(1000)
        ->and($oldChargeRow['feeWaived'])->toBeFalse()
        ->and($oldChargeRow['waivedFeeAmount'])->toBe(0)
        ->and(json_encode([$lineRow, $chargeRow]))->not->toContain('Secret reason');
});

// ---- Notice ----

test('the notice shows a running period or one starting within 30 days, with its dates only', function (string $startsAt, ?string $endsAt, bool $shown) {
    pfwaWaiver($startsAt, $endsAt, ['reason' => 'Secret reason']);

    $notice = PlatformFeeWaiverService::new()->getCommissionFreeNotice();

    if ($shown) {
        expect($notice)->toBe([
            'startsAt' => Carbon::parse($startsAt, 'UTC')->toIso8601ZuluString(),
            'endsAt' => $endsAt ? Carbon::parse($endsAt, 'UTC')->toIso8601ZuluString() : null,
        ]);
    } else {
        expect($notice)->toBeNull();
    }
})->with([
    'running' => ['2026-11-19 00:00', '2026-11-25 00:00', true],
    'running, no end' => ['2026-11-19 00:00', null, true],
    'starting within 30 days' => ['2026-12-15 00:00', '2026-12-20 00:00', true],
    'starting exactly 30 days ahead' => ['2026-12-20 12:00', '2026-12-25 00:00', true],
    'starting more than 30 days ahead' => ['2026-12-21 00:00', '2026-12-25 00:00', false],
    'ended' => ['2026-10-01 00:00', '2026-10-08 00:00', false],
    'ended exactly now' => ['2026-11-10 00:00', '2026-11-20 12:00', false],
]);

test('there is no notice for a cancelled period or a counsellor\'s own waiver', function () {
    $cancelled = pfwaWaiver('2026-11-19 00:00', '2026-11-20 11:00');
    $cancelled->forceFill(['ended_by_id' => pfwaAdmin()->id])->save();
    PlatformFeeWaiver::factory()->forCounsellor(Counsellor::factory()->create(['user_id' => User::factory()]))->create();

    expect(PlatformFeeWaiverService::new()->getCommissionFreeNotice())->toBeNull();
});

test('a running period is announced before an upcoming one', function () {
    pfwaWaiver('2026-11-25 00:00', '2026-11-30 00:00');
    pfwaWaiver('2026-11-19 00:00', '2026-11-25 00:00');

    expect(PlatformFeeWaiverService::new()->getCommissionFreeNotice()['startsAt'])->toBe('2026-11-19T00:00:00Z');
});

test('the counsellor earnings area carries the notice while a period is upcoming or running, and not otherwise', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);

    expect(GetCounsellorPayoutOverviewAction::new()->execute($counsellor)['commissionFreeNotice'])->toBeNull();

    pfwaWaiver('2026-12-01 00:00', '2026-12-08 00:00', ['reason' => 'Secret reason']);

    $overview = GetCounsellorPayoutOverviewAction::new()->execute($counsellor);
    expect($overview['commissionFreeNotice'])->toBe(['startsAt' => '2026-12-01T00:00:00Z', 'endsAt' => '2026-12-08T00:00:00Z']);

    $this->actingAs($counsellor->user)->get(route('counsellor.dashboard'))
        ->assertInertia(fn ($page) => $page->where('dashboard.earnings.commissionFreeNotice.startsAt', '2026-12-01T00:00:00Z'));
});

test('the organisation reconciliation page carries the notice while a period is upcoming or running, and not otherwise', function () {
    $organization = Organization::factory()->create(['is_consumer' => true, 'verified_at' => now()]);
    $owner = User::factory()->create();
    $organization->admins()->attach($owner->id, ['role' => OrganizationAdminRoleEnum::owner->value]);
    OrganizationInvoice::factory()->create(['organization_id' => $organization->id]);

    $this->actingAs($owner)->get(route('organizations.reconciliation', ['organizationId' => $organization->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Organization/Reconciliation')->where('commissionFreeNotice', null));

    pfwaWaiver('2026-11-19 00:00', '2026-11-25 00:00', ['reason' => 'Secret reason']);

    $this->actingAs($owner)->get(route('organizations.reconciliation', ['organizationId' => $organization->id]))
        ->assertInertia(fn ($page) => $page
            ->where('commissionFreeNotice', ['startsAt' => '2026-11-19T00:00:00Z', 'endsAt' => '2026-11-25T00:00:00Z'])
        )
        ->assertDontSee('Secret reason');
});

// ---- Seed data ----

test('the seeder adds an ended and an upcoming period (never a running one) with waived demo money of its own, once', function () {
    $superAdmin = User::factory()->create(['username' => 'mr_robertamoah']);
    User::factory()->create(['username' => 'payout_demo_client']);
    $member = User::factory()->create(['username' => 'org_demo_payperuse_member']);
    $organization = Organization::factory()->create(['name' => 'Org Demo Wellness Collective']);
    // The existing demo charge and its counsellor are left alone.
    $orgCounsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    $existingTherapy = Therapy::factory()->create(['name' => 'Org Pay-Per-Use Demo Therapy', 'addedby_type' => User::class, 'addedby_id' => $member->id, 'counsellor_id' => $orgCounsellor->id]);

    $this->seed(CommissionFreePeriodSeeder::class);
    $this->seed(CommissionFreePeriodSeeder::class);

    $periods = PlatformFeeWaiver::query()->platformWide()->orderBy('starts_at')->get();
    expect($periods)->toHaveCount(2)
        ->and($periods->map->status()->all())->toBe([PlatformFeeWaiver::ENDED, PlatformFeeWaiver::UPCOMING])
        ->and($periods->pluck('created_by_id')->unique()->all())->toBe([$superAdmin->id])
        ->and(PlatformFeeWaiverService::new()->getCommissionFreeNotice()['startsAt'])->toBe($periods[1]->starts_at->toIso8601ZuluString());

    $ended = $periods[0];
    $counsellor = User::query()->where('username', 'commission_free_demo_counsellor')->sole()->counsellor;
    $earnings = $counsellor->earnings()->orderBy('id')->get();
    expect($earnings)->toHaveCount(3)
        ->and($earnings[0]->platform_fee_waiver_id)->toBe($ended->id)
        ->and($earnings[0]->fee_amount)->toBe(0)
        ->and($earnings[1]->platform_fee_waiver_id)->toBeNull()
        ->and($earnings->pluck('currency')->unique()->all())->toBe(['GHS']);

    $charge = Transaction::query()->where('organization_id', $organization->id)->sole();
    $orgTherapy = Therapy::query()->where('name', CommissionFreePeriodSeeder::ORG_THERAPY)->sole();
    expect($charge->for_id)->toBe($orgTherapy->id)
        ->and($orgTherapy->counsellor_id)->toBe($counsellor->id)
        ->and($orgTherapy->addedby_id)->toBe($member->id)
        ->and($charge->breakdown['lines'][0]['platform_fee_waiver_id'])->toBe($ended->id)
        ->and($charge->created_at->between($ended->starts_at, $ended->ends_at))->toBeTrue()
        ->and($earnings[2]->transaction_id)->toBe($charge->id)
        ->and($earnings[2]->platform_fee_waiver_id)->toBe($ended->id)
        ->and(Transaction::query()->where('for_id', $existingTherapy->id)->where('for_type', Therapy::class)->exists())->toBeFalse()
        ->and(CounsellorEarning::query()->where('counsellor_id', $orgCounsellor->id)->exists())->toBeFalse();
});
