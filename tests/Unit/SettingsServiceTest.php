<?php

use App\DTOs\SettingDTO;
use App\Enums\AdministratorTypeEnum;
use App\Enums\SettingsEnum;
use App\Exceptions\UserException;
use App\Models\Administrator;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\SettingsService;

// TT-7.6b/SCRUM-226: the generic settings mechanism the platform fee and minimum-payout
// threshold both share (per the user's explicit decision -- not two separate config paths).

test('getPlatformFeePercentage falls back to the env-backed config default when unset', function () {
    expect(SettingsService::new()->getPlatformFeePercentage())->toBe((float) config('settings.platform_fee_percentage'));
});

test('an explicitly-stored empty string is treated as unset, not cast to a 0% fee', function () {
    PlatformSetting::create(['key' => SettingsEnum::platformFeePercentage->value, 'value' => '']);

    // Reviewer finding: a plain `??` null-coalesce would let '' through as "the fee is 0%",
    // silently different from "no override was ever set" -- must fall back to the config default.
    expect(SettingsService::new()->getPlatformFeePercentage())->toBe((float) config('settings.platform_fee_percentage'));
});

test('a super admin can set the platform fee percentage, and it then overrides the config default', function () {
    $superAdmin = User::factory()->has(Administrator::factory())->create();

    SettingsService::new()->update(SettingDTO::new()->fromArray([
        'user' => $superAdmin,
        'key' => SettingsEnum::platformFeePercentage,
        'value' => '15',
    ]));

    expect(SettingsService::new()->getPlatformFeePercentage())->toBe(15.0);
    $this->assertDatabaseHas('platform_settings', ['key' => SettingsEnum::platformFeePercentage->value, 'value' => '15']);
});

test('a non-super-admin cannot change a platform setting', function () {
    $plainUser = User::factory()->create();

    SettingsService::new()->update(SettingDTO::new()->fromArray([
        'user' => $plainUser,
        'key' => SettingsEnum::platformFeePercentage,
        'value' => '15',
    ]));
})->throws(UserException::class);

test('an admin who is not a super admin cannot change a platform setting either', function () {
    $admin = User::factory()->has(Administrator::factory()->state(['type' => AdministratorTypeEnum::normal->value]))->create();

    SettingsService::new()->update(SettingDTO::new()->fromArray([
        'user' => $admin,
        'key' => SettingsEnum::platformFeePercentage,
        'value' => '15',
    ]));
})->throws(UserException::class);

test('setting the same key twice updates it rather than creating a second row', function () {
    $superAdmin = User::factory()->has(Administrator::factory())->create();

    SettingsService::new()->update(SettingDTO::new()->fromArray([
        'user' => $superAdmin,
        'key' => SettingsEnum::platformFeePercentage,
        'value' => '12',
    ]));

    SettingsService::new()->update(SettingDTO::new()->fromArray([
        'user' => $superAdmin,
        'key' => SettingsEnum::platformFeePercentage,
        'value' => '20',
    ]));

    $this->assertDatabaseCount('platform_settings', 1);
    expect(SettingsService::new()->getPlatformFeePercentage())->toBe(20.0);
});

test('getMinimumPayoutAmount falls back to the per-currency config default when unset', function () {
    expect(SettingsService::new()->getMinimumPayoutAmount('GHS'))->toBe(config('settings.minimum_payout_amount.GHS'));
    expect(SettingsService::new()->getMinimumPayoutAmount('USD'))->toBe(config('settings.minimum_payout_amount.USD'));
});

test('a super admin can set the minimum payout threshold as a per-currency JSON map', function () {
    $superAdmin = User::factory()->has(Administrator::factory())->create();

    SettingsService::new()->update(SettingDTO::new()->fromArray([
        'user' => $superAdmin,
        'key' => SettingsEnum::minimumPayoutAmount,
        'value' => json_encode(['GHS' => 8000, 'USD' => 1500]),
    ]));

    expect(SettingsService::new()->getMinimumPayoutAmount('GHS'))->toBe(8000);
    expect(SettingsService::new()->getMinimumPayoutAmount('USD'))->toBe(1500);
});

test('a platform setting records which super admin last updated it', function () {
    $superAdmin = User::factory()->has(Administrator::factory())->create();

    $setting = SettingsService::new()->update(SettingDTO::new()->fromArray([
        'user' => $superAdmin,
        'key' => SettingsEnum::platformFeePercentage,
        'value' => '15',
    ]));

    expect($setting->updated_by_id)->toBe($superAdmin->id);
});
