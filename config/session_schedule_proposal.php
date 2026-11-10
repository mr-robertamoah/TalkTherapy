<?php

return [
    // SCRUM-206 (TT-2.5a): how long a session-schedule proposal/counter-offer stays open by
    // default -- mirrors config/organization.php's identical compensation-negotiation setting.
    // No expiry sweep job exists yet (out of this ticket's scope, unlike the compensation
    // feature's AppService sweep) -- this value is only recorded on the Request for now.
    'default_expiry_days' => (int) env('SESSION_SCHEDULE_PROPOSAL_DEFAULT_EXPIRY_DAYS', 7),

    // SCRUM-207 (TT-2.5b): maximum number of propose/counter-offer rounds before the responder's
    // only options collapse to accept/reject -- not yet enforced anywhere in TT-2.5a.
    'max_rounds' => (int) env('SESSION_SCHEDULE_PROPOSAL_MAX_ROUNDS', 5),
];
