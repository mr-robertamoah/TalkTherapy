<?php

return [
    // SCRUM-146 (TT-6.4c): how long a compensation-change proposal/counter-offer stays open
    // before AppService's daily sweep (SCRUM-149) auto-resolves it. The offerer can override this
    // per-offer; this is only the fallback default.
    'compensation_negotiation_default_expiry_days' => (int) env('ORG_COMPENSATION_NEGOTIATION_DEFAULT_EXPIRY_DAYS', 7),

    // SCRUM-148 (TT-6.4c): maximum number of proposal/counter-offer rounds in one negotiation
    // thread before the responder's only options collapse to accept/reject.
    'compensation_negotiation_max_rounds' => (int) env('ORG_COMPENSATION_NEGOTIATION_MAX_ROUNDS', 5),
];
