<?php

// SCRUM-37 (TT-5.2): outcome check-ins (PHQ-9, GAD-7). Built and tested, but switched off outside local and testing
// until the clinical and legal wording is read (the user's decision, like safety flags): every route is then a 404.
return [
    'enabled' => (bool) env('CHECK_INS_ENABLED', in_array(env('APP_ENV'), ['local', 'testing'], true)),
    // How long a client has to answer one.
    'expires_days' => max(1, (int) env('CHECK_INS_EXPIRE_DAYS', 7)),
    // How many a counsellor may send in a day, across their clients.
    'per_counsellor_per_day' => max(1, (int) env('CHECK_INS_PER_COUNSELLOR_PER_DAY', 20)),
];
