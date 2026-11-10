<?php

return [
    // SCRUM-153 (TT-7.2a): the single, platform-wide list of currencies accepted anywhere
    // currency is entered or charged -- Therapy/GroupTherapy payment_data, and defense-in-depth
    // at Paystack charge initiation. Env-overridable so adding/removing a supported currency is
    // a one-line change, not a code change (mirrors config/organization.php's tunable-list style).
    // Normalized to uppercase here, once, so every consumer (Rule::in(), the charge-initiation
    // guard) compares against the same casing without each needing its own normalization step.
    // array_filter drops empty entries (a stray comma or whitespace-only segment in the env
    // value), so Rule::in() never ends up accepting an empty string as a "valid" currency.
    'supported' => array_values(array_filter(array_map(
        fn (string $currency) => strtoupper(trim($currency)),
        explode(',', env('SUPPORTED_CURRENCIES') ?: 'USD,GHS')
    ))),
];
