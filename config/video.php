<?php

return [

    // TT-3.1a/SCRUM-274: which VideoProviderInterface adapter is active for this deployment --
    // a deployment-level choice, not a live per-session runtime switch (user's own explicit
    // decision, 2026-09-11: see documentation/decision-log.md). Only one of 'daily'/'chime' is
    // ever bound in the container at a time (see App\Providers\VideoServiceProvider).
    'provider' => env('VIDEO_PROVIDER', 'daily'),

    // TT-3.2f-h/SCRUM-325: seconds a granted speaking permission may go without an activity ping
    // from the member's client before the scheduled sweep reverts them to receive-only. A client
    // that stops pinging can only ever LOSE permission -- never gain any -- since the publish
    // capability itself is enforced provider-side.
    // Floored at 30s so a blank/zero env value can't revoke every open grant on the next sweep.
    'speaking_grant_idle_timeout' => max(30, (int) env('VIDEO_SPEAKING_GRANT_IDLE_TIMEOUT', 300)),

];
