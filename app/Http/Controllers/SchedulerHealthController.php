<?php

namespace App\Http\Controllers;

use App\Enums\HelpRequestStatusEnum;
use App\Models\HelpRequest;
use App\Support\SchedulerHeartbeat;
use Illuminate\Http\JsonResponse;

// SCRUM-373: for a monitor to poll. 503 when the scheduler hasn't run for a while -- on 2026-11-02 it
// was down for ~7.5 hours after a host suspend and nothing noticed. Only says when it last ran.
// SCRUM-395: also 503 when an urgent help request has waited past its expiry plus two minutes -- the
// minute sweep that expires them has stopped or is failing. Only yes or no, never which request.
class SchedulerHealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $lastRunAt = SchedulerHeartbeat::lastRunAt();
        $helpOverdue = HelpRequest::query()->whereStatus(HelpRequestStatusEnum::waiting)
            ->where('created_at', '<=', now()->subMinutes((int) config('help.expire_after_minutes') + 2))
            ->exists();
        $healthy = SchedulerHeartbeat::isHealthy() && ! $helpOverdue;

        return response()->json([
            'healthy' => $healthy,
            'lastRunAt' => $lastRunAt?->toIso8601String(),
            'helpRequestsOverdue' => $helpOverdue,
        ], $healthy ? 200 : 503);
    }
}
