<?php

return [
    // TT-7.6b (SCRUM-226): fallback used by SettingsService::get() only when no
    // PlatformSetting row exists yet for the key -- once a super admin sets a value via
    // UpdateSettingAction, the DB row takes precedence over this env default.
    'platform_fee_percentage' => (int) env('PLATFORM_FEE_PERCENTAGE', 10),

    // Minor units (pesewas/cents), same convention as transactions.amount -- keyed by the
    // currencies this platform supports (config/currencies.php).
    'minimum_payout_amount' => [
        'GHS' => (int) env('MINIMUM_PAYOUT_AMOUNT_GHS', 5000),
        'USD' => (int) env('MINIMUM_PAYOUT_AMOUNT_USD', 1000),
    ],
];
