<?php

return [
    // TT-7.10a/SCRUM-467 (security review): a personal payment's platform fee is decided as it stood when checkout
    // started (transactions.created_at). A waiver in force then is kept only if the payment succeeds within this many
    // minutes, or while that same waiver is still in force -- so a checkout opened during a fee-free period and paid
    // long after it ended doesn't skip the fee.
    'fee_waiver_checkout_grace_minutes' => (int) env('PAYMENTS_FEE_WAIVER_CHECKOUT_GRACE_MINUTES', 30),
];
