<?php

use App\Enums\AdministratorTypeEnum;
use App\Enums\CounsellorEarningStatusEnum;
use App\Enums\CounsellorPayoutStatusEnum;
use App\Enums\CounsellorPayoutStatusSourceEnum;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\CounsellorEarning;
use App\Models\CounsellorPayout;
use App\Models\CounsellorPayoutAccount;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

// TT-7.6e/SCRUM-229: the admin-facing payout HTTP boundary -- reuses TT-7.6c's already-reviewed
// TriggerCounsellorPayoutAction/GetPayoutTargetCounsellorAction (payout.trigger route), so these
// tests focus on what's new here: the settings form endpoints, the audit listing, and the
// per-counsellor overview endpoint, all independently admin-gated (not just the page-level gate).

function anAdminUserForPayoutController(bool $super = true): User
{
    return User::factory()
        ->has(Administrator::factory()->state([
            'type' => $super ? AdministratorTypeEnum::super->value : AdministratorTypeEnum::normal->value,
        ]))
        ->create();
}

function aTransactionForAdminPayoutTest(): Transaction
{
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);

    return Transaction::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id]);
}

test('a non-admin cannot visit the admin payout page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('administrator.payouts'));

    $response->assertRedirect(route('home'));
});

test('a super admin can update the platform fee percentage', function () {
    $admin = anAdminUserForPayoutController(super: true);

    $response = $this->actingAs($admin)->post(route('admin.settings.platform-fee.update'), [
        'percentage' => 15,
    ]);

    $response->assertSessionHasNoErrors();
    $this->assertDatabaseHas('platform_settings', ['key' => 'PLATFORM_FEE_PERCENTAGE', 'value' => '15']);
});

test('the platform fee percentage must be within 0-100', function () {
    $admin = anAdminUserForPayoutController(super: true);

    $response = $this->actingAs($admin)->post(route('admin.settings.platform-fee.update'), [
        'percentage' => 150,
    ]);

    $response->assertSessionHasErrors('percentage');
});

test('a normal (non-super) admin cannot update the platform fee percentage', function () {
    $admin = anAdminUserForPayoutController(super: false);

    $response = $this->actingAs($admin)->post(route('admin.settings.platform-fee.update'), [
        'percentage' => 15,
    ]);

    $response->assertSessionHasErrors('alert');
    $this->assertDatabaseMissing('platform_settings', ['key' => 'PLATFORM_FEE_PERCENTAGE']);
});

test('a super admin can update minimum payout amounts for every supported currency at once', function () {
    config(['currencies.supported' => ['GHS', 'USD']]);
    $admin = anAdminUserForPayoutController(super: true);

    $response = $this->actingAs($admin)->post(route('admin.settings.minimum-payout.update'), [
        'amounts' => [
            ['currency' => 'GHS', 'amount' => 60],
            ['currency' => 'USD', 'amount' => 12],
        ],
    ]);

    $response->assertSessionHasNoErrors();
    $this->assertDatabaseHas('platform_settings', [
        'key' => 'MINIMUM_PAYOUT_AMOUNT',
        'value' => json_encode(['GHS' => 6000, 'USD' => 1200]),
    ]);
});

test('a partial minimum payout amounts submission is rejected rather than silently dropping a currency', function () {
    config(['currencies.supported' => ['GHS', 'USD']]);
    $admin = anAdminUserForPayoutController(super: true);

    $response = $this->actingAs($admin)->post(route('admin.settings.minimum-payout.update'), [
        'amounts' => [
            ['currency' => 'GHS', 'amount' => 60],
        ],
    ]);

    $response->assertSessionHasErrors('amounts');
});

test('a same-size submission repeating one currency is rejected rather than silently dropping the omitted one', function () {
    // security-engineer finding: `size:N` alone doesn't guarantee N *distinct* currencies -- two
    // GHS rows (size 2) would otherwise pass while USD is silently dropped from the stored JSON.
    config(['currencies.supported' => ['GHS', 'USD']]);
    $admin = anAdminUserForPayoutController(super: true);

    $response = $this->actingAs($admin)->post(route('admin.settings.minimum-payout.update'), [
        'amounts' => [
            ['currency' => 'GHS', 'amount' => 60],
            ['currency' => 'GHS', 'amount' => 70],
        ],
    ]);

    $response->assertSessionHasErrors('amounts.0.currency');
    $this->assertDatabaseMissing('platform_settings', ['key' => 'MINIMUM_PAYOUT_AMOUNT']);
});

test('a normal (non-super) admin cannot update minimum payout amounts', function () {
    config(['currencies.supported' => ['GHS', 'USD']]);
    $admin = anAdminUserForPayoutController(super: false);

    $response = $this->actingAs($admin)->post(route('admin.settings.minimum-payout.update'), [
        'amounts' => [
            ['currency' => 'GHS', 'amount' => 60],
            ['currency' => 'USD', 'amount' => 12],
        ],
    ]);

    $response->assertSessionHasErrors('alert');
    $this->assertDatabaseMissing('platform_settings', ['key' => 'MINIMUM_PAYOUT_AMOUNT']);
});

test('an admin can list all payouts across counsellors via the audit endpoint', function () {
    $admin = anAdminUserForPayoutController(super: true);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    CounsellorPayout::factory()->create(['counsellor_id' => $counsellor->id, 'status' => CounsellorPayoutStatusEnum::succeeded->value]);
    $failedPayout = CounsellorPayout::factory()->create(['counsellor_id' => $counsellor->id, 'status' => CounsellorPayoutStatusEnum::failed->value]);
    $failedPayout->statusHistories()->create([
        'status' => CounsellorPayoutStatusEnum::failed->value,
        'source' => CounsellorPayoutStatusSourceEnum::webhook->value,
        'message' => 'Paystack could not initiate this transfer.',
    ]);

    $response = $this->actingAs($admin)->getJson(route('admin.payouts'));

    $response->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveCount(2);
    $failedRow = collect($data)->firstWhere('id', $failedPayout->id);
    expect($failedRow['failureMessage'])->toBe('Paystack could not initiate this transfer.');
});

test('a non-admin is refused (not silently shown an empty list) at the audit endpoint', function () {
    $user = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    CounsellorPayout::factory()->create(['counsellor_id' => $counsellor->id]);

    $response = $this->actingAs($user)->getJson(route('admin.payouts'));

    $response->assertStatus(422);
});

test('an admin can view a specific counsellor\'s payout overview', function () {
    $admin = anAdminUserForPayoutController(super: true);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    CounsellorPayoutAccount::factory()->create(['counsellor_id' => $counsellor->id, 'currency' => 'GHS']);
    CounsellorEarning::factory()->create([
        'transaction_id' => aTransactionForAdminPayoutTest()->id,
        'counsellor_id' => $counsellor->id,
        'net_amount' => 8000,
        'currency' => 'GHS',
        'status' => CounsellorEarningStatusEnum::pending->value,
    ]);

    $response = $this->actingAs($admin)->getJson(route('admin.payouts.counsellor-overview', ['counsellorId' => $counsellor->id]));

    $response->assertOk();
    $response->assertJsonPath('availableAmount', 8000);
    $response->assertJsonPath('currency', 'GHS');
});

test('a non-admin is refused when viewing a counsellor\'s payout overview', function () {
    $user = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);

    $response = $this->actingAs($user)->getJson(route('admin.payouts.counsellor-overview', ['counsellorId' => $counsellor->id]));

    $response->assertStatus(422);
});

test('a nonexistent counsellor id on the overview endpoint returns a clear 404, not a crash', function () {
    $admin = anAdminUserForPayoutController(super: true);

    $response = $this->actingAs($admin)->getJson(route('admin.payouts.counsellor-overview', ['counsellorId' => 999999]));

    $response->assertStatus(404);
});

test('an admin can trigger a payout on a counsellor\'s behalf from the admin page (reusing payout.trigger)', function () {
    Bus::fake();
    config(['settings.minimum_payout_amount.GHS' => 1000]);
    $admin = anAdminUserForPayoutController(super: true);
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    CounsellorPayoutAccount::factory()->create(['counsellor_id' => $counsellor->id, 'currency' => 'GHS']);
    CounsellorEarning::factory()->create([
        'transaction_id' => aTransactionForAdminPayoutTest()->id,
        'counsellor_id' => $counsellor->id,
        'net_amount' => 10000,
        'currency' => 'GHS',
        'status' => CounsellorEarningStatusEnum::pending->value,
    ]);

    $response = $this->actingAs($admin)->post(route('payout.trigger'), ['counsellorId' => $counsellor->id]);

    $response->assertSessionHasNoErrors();
    $this->assertDatabaseHas('counsellor_payouts', [
        'counsellor_id' => $counsellor->id,
        'initiated_by_id' => $admin->id,
        'status' => CounsellorPayoutStatusEnum::pending->value,
    ]);
});
