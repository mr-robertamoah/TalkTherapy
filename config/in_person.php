<?php

// SCRUM-447 (TT-2.9f, the user's decision on SCRUM-115): an in-person session's exact map pin (latitude/longitude)
// is cleared this many days after the session's end time; its written landmark is kept. ClearOldSessionPinsAction,
// daily; the privacy policy and "Your data" read this value.
return [
    // Days. Not a number, or under ClearOldSessionPinsAction::MINIMUM_DAYS (1), skips the run with an error logged --
    // a mistyped setting never clears more than intended. Empty or 0 means the default, 30.
    'pin_retention_days' => env('SESSION_PIN_RETENTION_DAYS') ?: 30,
];
