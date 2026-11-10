<?php

return [
    // SCRUM-197: once a session leaves its live window (HELD/FAILED/ABANDONED), a note's author
    // has this many minutes -- counted from the session's last status change (Session::updated_at
    // -- there's no dedicated "ended at" column) -- to still edit/delete it before it becomes a
    // permanent, immutable part of the clinical record. Configurable so the grace period can be
    // tuned without a deploy.
    'edit_grace_minutes' => (int) env('SESSION_NOTES_EDIT_GRACE_MINUTES', 30),
];
