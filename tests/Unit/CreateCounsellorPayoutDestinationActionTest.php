<?php

use App\DTOs\PayoutDestinationDTO;
use App\Enums\PayoutDestinationTypeEnum;
use App\Exceptions\PayoutException;
use App\Models\Counsellor;
use App\Models\CounsellorPayoutAccount;
use App\Models\User;
use App\Notifications\PayoutDestinationChangedNotification;
use App\Services\PayoutService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

// TT-7.6a/SCRUM-225: payout-destination onboarding via Paystack Transfer Recipients.

function fakesPaystackForPayoutOnboarding(string $recipientCode = 'RCP_test_1', array $overrides = []): void
{
    Http::fake(array_merge([
        '*/bank/resolve*' => Http::response([
            'status' => true,
            'data' => ['account_number' => '0123456789', 'account_name' => 'Jane Counsellor', 'bank_name' => 'Test Bank'],
        ], 200),
        '*/transferrecipient' => Http::response([
            'status' => true,
            'data' => ['recipient_code' => $recipientCode, 'details' => ['bank_name' => 'Test Bank']],
        ], 200),
    ], $overrides));
}

function aVerifiedCounsellorUser(): array
{
    $counsellorUser = User::factory()->create();
    $counsellor = Counsellor::factory()->create(['user_id' => $counsellorUser->id, 'verified_at' => now()]);

    return [$counsellorUser, $counsellor];
}

test('a verified counsellor can onboard a bank-account payout destination', function () {
    fakesPaystackForPayoutOnboarding();
    [$counsellorUser, $counsellor] = aVerifiedCounsellorUser();

    $destination = PayoutService::new()->onboardDestination(PayoutDestinationDTO::new()->fromArray([
        'user' => $counsellorUser,
        'type' => PayoutDestinationTypeEnum::nuban,
        'accountNumber' => '0123456789',
        'bankCode' => '057',
        'currency' => 'GHS',
    ]));

    expect($destination->counsellor_id)->toBe($counsellor->id);
    expect($destination->type)->toBe(PayoutDestinationTypeEnum::nuban->value);
    expect($destination->recipient_code)->toBe('RCP_test_1');
    expect($destination->account_name)->toBe('Jane Counsellor');
    expect($destination->masked_account_number)->toBe('**** 6789');
    $this->assertDatabaseCount('counsellor_payout_accounts', 1);
});

test('a verified counsellor can onboard a mobile-money payout destination', function () {
    fakesPaystackForPayoutOnboarding('RCP_momo_1');
    [$counsellorUser, $counsellor] = aVerifiedCounsellorUser();

    $destination = PayoutService::new()->onboardDestination(PayoutDestinationDTO::new()->fromArray([
        'user' => $counsellorUser,
        'type' => PayoutDestinationTypeEnum::mobileMoney,
        'accountNumber' => '0551234567',
        'bankCode' => 'MTN',
        'currency' => 'GHS',
    ]));

    expect($destination->type)->toBe(PayoutDestinationTypeEnum::mobileMoney->value);
});

test('an unverified counsellor cannot onboard a payout destination', function () {
    $counsellorUser = User::factory()->create();
    Counsellor::factory()->create(['user_id' => $counsellorUser->id, 'verified_at' => null]);

    PayoutService::new()->onboardDestination(PayoutDestinationDTO::new()->fromArray([
        'user' => $counsellorUser,
        'type' => PayoutDestinationTypeEnum::nuban,
        'accountNumber' => '0123456789',
        'bankCode' => '057',
        'currency' => 'GHS',
    ]));
})->throws(PayoutException::class);

test('a non-counsellor user cannot onboard a payout destination', function () {
    $plainUser = User::factory()->create();

    PayoutService::new()->onboardDestination(PayoutDestinationDTO::new()->fromArray([
        'user' => $plainUser,
        'type' => PayoutDestinationTypeEnum::nuban,
        'accountNumber' => '0123456789',
        'bankCode' => '057',
        'currency' => 'GHS',
    ]));
})->throws(PayoutException::class);

test('a failed account-resolve call is surfaced as a clean PayoutException, not an uncaught 500', function () {
    Http::fake(['*/bank/resolve*' => Http::response(['status' => false, 'message' => 'Could not resolve account'], 422)]);
    [$counsellorUser, $counsellor] = aVerifiedCounsellorUser();

    PayoutService::new()->onboardDestination(PayoutDestinationDTO::new()->fromArray([
        'user' => $counsellorUser,
        'type' => PayoutDestinationTypeEnum::nuban,
        'accountNumber' => '0123456789',
        'bankCode' => '057',
        'currency' => 'GHS',
    ]));
})->throws(PayoutException::class);

test('a failed recipient-creation call is surfaced as a clean PayoutException, not an uncaught 500', function () {
    Http::fake([
        '*/bank/resolve*' => Http::response(['status' => true, 'data' => ['account_number' => '0123456789', 'account_name' => 'Jane Counsellor']], 200),
        '*/transferrecipient' => Http::response(['status' => false, 'message' => 'failed'], 502),
    ]);
    [$counsellorUser, $counsellor] = aVerifiedCounsellorUser();

    PayoutService::new()->onboardDestination(PayoutDestinationDTO::new()->fromArray([
        'user' => $counsellorUser,
        'type' => PayoutDestinationTypeEnum::nuban,
        'accountNumber' => '0123456789',
        'bankCode' => '057',
        'currency' => 'GHS',
    ]));
})->throws(PayoutException::class);

test('the raw account number is never persisted, only a masked version', function () {
    fakesPaystackForPayoutOnboarding();
    [$counsellorUser, $counsellor] = aVerifiedCounsellorUser();

    PayoutService::new()->onboardDestination(PayoutDestinationDTO::new()->fromArray([
        'user' => $counsellorUser,
        'type' => PayoutDestinationTypeEnum::nuban,
        'accountNumber' => '0123456789',
        'bankCode' => '057',
        'currency' => 'GHS',
    ]));

    $this->assertDatabaseMissing('counsellor_payout_accounts', ['masked_account_number' => '0123456789']);
    $stored = CounsellorPayoutAccount::first();
    expect($stored->masked_account_number)->not->toContain('012345');
});

test('onboarding for the first time does not send a destination-changed notification', function () {
    Notification::fake();
    fakesPaystackForPayoutOnboarding();
    [$counsellorUser, $counsellor] = aVerifiedCounsellorUser();

    PayoutService::new()->onboardDestination(PayoutDestinationDTO::new()->fromArray([
        'user' => $counsellorUser,
        'type' => PayoutDestinationTypeEnum::nuban,
        'accountNumber' => '0123456789',
        'bankCode' => '057',
        'currency' => 'GHS',
    ]));

    Notification::assertNothingSent();
});

test('replacing an existing payout destination sends a destination-changed notification', function () {
    Notification::fake();
    // Http::fake() resolves the FIRST-registered matching stub, not the most recent one -- a
    // second call registering the same URL pattern would never be reached. A response sequence
    // is the correct way to vary /transferrecipient's response across the two onboarding calls
    // this test makes.
    Http::fake([
        '*/bank/resolve*' => Http::response([
            'status' => true,
            'data' => ['account_number' => '0123456789', 'account_name' => 'Jane Counsellor', 'bank_name' => 'Test Bank'],
        ], 200),
        '*/transferrecipient' => Http::sequence()
            ->push(['status' => true, 'data' => ['recipient_code' => 'RCP_first', 'details' => ['bank_name' => 'Test Bank']]], 200)
            ->push(['status' => true, 'data' => ['recipient_code' => 'RCP_second', 'details' => ['bank_name' => 'Test Bank']]], 200),
    ]);
    [$counsellorUser, $counsellor] = aVerifiedCounsellorUser();

    PayoutService::new()->onboardDestination(PayoutDestinationDTO::new()->fromArray([
        'user' => $counsellorUser,
        'type' => PayoutDestinationTypeEnum::nuban,
        'accountNumber' => '0123456789',
        'bankCode' => '057',
        'currency' => 'GHS',
    ]));

    PayoutService::new()->onboardDestination(PayoutDestinationDTO::new()->fromArray([
        'user' => $counsellorUser,
        'type' => PayoutDestinationTypeEnum::nuban,
        'accountNumber' => '9876543210',
        'bankCode' => '058',
        'currency' => 'GHS',
    ]));

    Notification::assertSentTo($counsellor, PayoutDestinationChangedNotification::class);
    $this->assertDatabaseCount('counsellor_payout_accounts', 1);
    expect(CounsellorPayoutAccount::first()->recipient_code)->toBe('RCP_second');
});
