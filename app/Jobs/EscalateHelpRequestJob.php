<?php

namespace App\Jobs;

use App\Actions\Help\EscalateHelpRequestsAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

// SCRUM-33 (TT-4.4b): checks one help request when its alert time comes (dispatched, delayed, when it
// is asked). The every-minute sweep does the same for anything this misses; both are idempotent.
class EscalateHelpRequestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public int $helpRequestId) {}

    public function handle(): void
    {
        EscalateHelpRequestsAction::new()->execute($this->helpRequestId);
    }
}
