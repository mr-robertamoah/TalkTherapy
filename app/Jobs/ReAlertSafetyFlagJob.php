<?php

namespace App\Jobs;

use App\Actions\Safety\ReAlertSafetyFlagsAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

// SCRUM-32 (TT-4.3b): checks one safety flag when its re-alert time comes (dispatched, delayed, when it is
// raised). The sweep (every five minutes) does the same for anything this misses; both are idempotent.
class ReAlertSafetyFlagJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public int $safetyFlagId) {}

    public function handle(): void
    {
        ReAlertSafetyFlagsAction::new()->execute($this->safetyFlagId);
    }
}
