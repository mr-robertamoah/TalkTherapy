<?php

use App\Enums\CounsellorEarningStatusEnum;
use App\Enums\CounsellorPayoutStatusEnum;
use App\Enums\PayoutDestinationTypeEnum;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\CounsellorEarning;
use App\Models\CounsellorPayoutAccount;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

// TT-7.6d/SCRUM-228: the HTTP boundary this ticket's own security review required -- DTO->user
// built strictly from the authenticated request user, never from request input.

function aVerifiedCounsellorUserForController(): array
{
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id, 'verified_at' => now()]);

    return [$counsellorUser, $counsellor];
}

test('a verified counsellor can onboard a payout destination via the real HTTP endpoint', function () {
    Http::fake([
        '*/bank/resolve*' => Http::response(['status' => true, 'data' => ['account_number' => '0123456789', 'account_name' => 'Jane Counsellor']], 200),
        '*/transferrecipient' => Http::response(['status' => true, 'data' => ['recipient_code' => 'RCP_1']], 200),
    ]);
    [$counsellorUser, $counsellor] = aVerifiedCounsellorUserForController();

    $response = $this->actingAs($counsellorUser)->post(route('payout.destination.store'), [
        'type' => PayoutDestinationTypeEnum::nuban->value,
        'accountNumber' => '0123456789',
        'bankCode' => '057',
        'currency' => 'GHS',
    ]);

    $response->assertSessionHasNoErrors();
    $this->assertDatabaseHas('counsellor_payout_accounts', ['counsellor_id' => $counsellor->id, 'recipient_code' => 'RCP_1']);
});

test('an unverified counsellor is refused at the real HTTP endpoint', function () {
    $counsellorUser = User::factory()->create();
    Counsellor::factory()->create(['user_id' => $counsellorUser->id, 'verified_at' => null]);

    $response = $this->actingAs($counsellorUser)->post(route('payout.destination.store'), [
        'type' => PayoutDestinationTypeEnum::nuban->value,
        'accountNumber' => '0123456789',
        'bankCode' => '057',
        'currency' => 'GHS',
    ]);

    $response->assertSessionHasErrors('alert');
    $this->assertDatabaseCount('counsellor_payout_accounts', 0);
});

test('onboarding validates its input shape', function () {
    [$counsellorUser, $counsellor] = aVerifiedCounsellorUserForController();

    $response = $this->actingAs($counsellorUser)->post(route('payout.destination.store'), []);

    $response->assertSessionHasErrors(['type', 'accountNumber', 'bankCode', 'currency']);
});

test('the DTO user always comes from the authenticated request, never from request input', function () {
    // A malicious/confused client submitting a userId field has no effect -- PayoutController
    // never reads anything but $request->user() for the DTO's user.
    Http::fake([
        '*/bank/resolve*' => Http::response(['status' => true, 'data' => ['account_number' => '9', 'account_name' => 'X']], 200),
        '*/transferrecipient' => Http::response(['status' => true, 'data' => ['recipient_code' => 'RCP_2']], 200),
    ]);
    [$counsellorUser, $counsellor] = aVerifiedCounsellorUserForController();
    $otherUser = User::factory()->create();

    $this->actingAs($counsellorUser)->post(route('payout.destination.store'), [
        'type' => PayoutDestinationTypeEnum::nuban->value,
        'accountNumber' => '9',
        'bankCode' => '057',
        'currency' => 'GHS',
        'user' => $otherUser->id,
        'userId' => $otherUser->id,
    ]);

    $this->assertDatabaseHas('counsellor_payout_accounts', ['counsellor_id' => $counsellor->id]);
});

test('a counsellor can trigger their own payout via the real HTTP endpoint', function () {
    Bus::fake();
    config(['settings.minimum_payout_amount.GHS' => 1000]);
    [$counsellorUser, $counsellor] = aVerifiedCounsellorUserForController();
    CounsellorPayoutAccount::factory()->create(['counsellor_id' => $counsellor->id, 'currency' => 'GHS']);
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);
    $transaction = Transaction::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id]);
    CounsellorEarning::factory()->create([
        'transaction_id' => $transaction->id,
        'counsellor_id' => $counsellor->id,
        'net_amount' => 10000,
        'currency' => 'GHS',
        'status' => CounsellorEarningStatusEnum::pending->value,
    ]);

    $response = $this->actingAs($counsellorUser)->post(route('payout.trigger'));

    $response->assertSessionHasNoErrors();
    $this->assertDatabaseHas('counsellor_payouts', ['counsellor_id' => $counsellor->id, 'status' => CounsellorPayoutStatusEnum::pending->value]);
});

test('a non-admin cannot trigger a payout for another counsellor by supplying their counsellorId', function () {
    Bus::fake();
    config(['settings.minimum_payout_amount.GHS' => 1000]);
    [$counsellorUser, $counsellor] = aVerifiedCounsellorUserForController();
    [$otherCounsellorUser, $otherCounsellor] = aVerifiedCounsellorUserForController();
    CounsellorPayoutAccount::factory()->create(['counsellor_id' => $otherCounsellor->id, 'currency' => 'GHS']);
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);
    $transaction = Transaction::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id]);
    CounsellorEarning::factory()->create([
        'transaction_id' => $transaction->id,
        'counsellor_id' => $otherCounsellor->id,
        'net_amount' => 10000,
        'currency' => 'GHS',
        'status' => CounsellorEarningStatusEnum::pending->value,
    ]);

    // $counsellorUser has no payout destination/earnings of their own -- if counsellorId were
    // wrongly honored for a non-admin, this would succeed against $otherCounsellor's balance.
    $response = $this->actingAs($counsellorUser)->post(route('payout.trigger'), [
        'counsellorId' => $otherCounsellor->id,
    ]);

    $response->assertSessionHasErrors('alert');
    $this->assertDatabaseCount('counsellor_payouts', 0);
});

test('an admin can trigger a payout on a counsellor\'s behalf via the real HTTP endpoint', function () {
    Bus::fake();
    config(['settings.minimum_payout_amount.GHS' => 1000]);
    [$counsellorUser, $counsellor] = aVerifiedCounsellorUserForController();
    CounsellorPayoutAccount::factory()->create(['counsellor_id' => $counsellor->id, 'currency' => 'GHS']);
    $admin = User::factory()->has(Administrator::factory())->create();
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()]);
    $transaction = Transaction::factory()->create(['for_type' => Therapy::class, 'for_id' => $therapy->id]);
    CounsellorEarning::factory()->create([
        'transaction_id' => $transaction->id,
        'counsellor_id' => $counsellor->id,
        'net_amount' => 10000,
        'currency' => 'GHS',
        'status' => CounsellorEarningStatusEnum::pending->value,
    ]);

    $response = $this->actingAs($admin)->post(route('payout.trigger'), [
        'counsellorId' => $counsellor->id,
    ]);

    $response->assertSessionHasNoErrors();
    $this->assertDatabaseHas('counsellor_payouts', ['counsellor_id' => $counsellor->id, 'initiated_by_id' => $admin->id]);
});
