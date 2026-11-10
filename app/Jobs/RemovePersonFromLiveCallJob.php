<?php

namespace App\Jobs;

use App\Actions\Video\RemovePersonFromLiveCallsAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

// SCRUM-382 (was SCRUM-288's RemovePausedMinorFromVideoJob): the queued retry of taking a person
// out of one session's live call, when RemovePersonFromLiveCallsAction couldn't do it at once (their
// join lock was busy, or something failed). Each run re-checks under the lock, by the same policy,
// so a retry never removes someone who has since been let back.
class RemovePersonFromLiveCallJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 4;

    public $backoff = [10, 30, 90];

    public function __construct(
        private int $sessionId,
        private int $personId,
        private string $reason,
        private ?int $suspensionId = null,
    ) {}

    public function handle(): void
    {
        // A busy lock (LockTimeoutException) or a failure throws: the queue tries again.
        RemovePersonFromLiveCallsAction::new()->removeFrom($this->personId, $this->sessionId, $this->reason, $this->suspensionId);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Could not take a person out of a live call; they may still be in it.', [
            'session_id' => $this->sessionId,
            'person' => $this->personId,
            'reason' => $this->reason,
            'exception' => $exception->getMessage(),
        ]);
    }
}
