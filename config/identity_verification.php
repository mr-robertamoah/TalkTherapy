<?php

return [

    // TT-4.11a/SCRUM-302: how long an identity document stays on disk after its owning Request
    // has been decided (accepted/rejected), before the scheduled deletion job removes it.
    // Configurable per the user's own explicit decision (2026-09-13), 30 days by default.
    'document_retention_days' => (int) env('IDENTITY_DOCUMENT_RETENTION_DAYS', 30),

];
