<?php

return [

    // TT-3.1a/SCRUM-274: which VideoProviderInterface adapter is active for this deployment --
    // a deployment-level choice, not a live per-session runtime switch (user's own explicit
    // decision, 2026-09-11: see documentation/decision-log.md). Only one of 'daily'/'chime' is
    // ever bound in the container at a time (see App\Providers\VideoServiceProvider).
    'provider' => env('VIDEO_PROVIDER', 'daily'),

];
