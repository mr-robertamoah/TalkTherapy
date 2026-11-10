<?php

return [
    // SCRUM-248: an org-financed pay-per-use charge still pending this long (its webhook never came)
    // is asked about directly (ReconcileStalePendingOrganizationChargesAction, every 15 minutes).
    'stale_charge_reconcile_after_minutes' => (int) env('ORG_STALE_CHARGE_RECONCILE_AFTER_MINUTES', 30),
    // ...and one still unresolved after this long is logged for manual follow-up on every run.
    'stale_charge_alert_after_hours' => (int) env('ORG_STALE_CHARGE_ALERT_AFTER_HOURS', 24),

    // SCRUM-146 (TT-6.4c): how long a compensation-change proposal/counter-offer stays open
    // before AppService's daily sweep (SCRUM-149) auto-resolves it. The offerer can override this
    // per-offer; this is only the fallback default.
    'compensation_negotiation_default_expiry_days' => (int) env('ORG_COMPENSATION_NEGOTIATION_DEFAULT_EXPIRY_DAYS', 7),

    // SCRUM-148 (TT-6.4c): maximum number of proposal/counter-offer rounds in one negotiation
    // thread before the responder's only options collapse to accept/reject.
    'compensation_negotiation_max_rounds' => (int) env('ORG_COMPENSATION_NEGOTIATION_MAX_ROUNDS', 5),
];
