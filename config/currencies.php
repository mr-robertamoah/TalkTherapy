<?php

return [
    // SCRUM-153 (TT-7.2a): the single, platform-wide list of currencies accepted anywhere
    // currency is entered or charged -- Therapy/GroupTherapy payment_data, and defense-in-depth
    // at Paystack charge initiation. Env-overridable so adding/removing a supported currency is
    // a one-line change, not a code change (mirrors config/organization.php's tunable-list style).
    'supported' => array_map('trim', explode(',', env('SUPPORTED_CURRENCIES', 'USD,GHS'))),
];
