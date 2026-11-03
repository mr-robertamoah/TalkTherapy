<?php

namespace App\Http\Controllers;

use App\Support\SchedulerHeartbeat;
use Illuminate\Http\JsonResponse;

// SCRUM-373: for a monitor to poll. 503 when the scheduler hasn't run for a while -- on 2026-11-02 it
// was down for ~7.5 hours after a host suspend and nothing noticed. Only says when it last ran.
class SchedulerHealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $lastRunAt = SchedulerHeartbeat::lastRunAt();
        $healthy = SchedulerHeartbeat::isHealthy();

        return response()->json([
            'healthy' => $healthy,
            'lastRunAt' => $lastRunAt?->toIso8601String(),
        ], $healthy ? 200 : 503);
    }
}
