<?php

namespace App\Jobs;

use App\Actions\Guardianship\HandleGuardianLossAction;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

// SCRUM-382: runs HandleGuardianLossAction's outbound work (live-call removal, broadcasts,
// notifications) off the request, once the decision that the ward is newly paused has committed
// (HandleGuardianLossAction::schedule()). It re-checks that they are still paused. One try: every
// step inside is best effort, and a second run could tell people twice; a failed live-call removal
// has its own retry (RemovePersonFromLiveCallJob).
class HandleGuardianLossJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 1;

    public function __construct(private int $wardId, private string $trigger) {}

    public function handle(): void
    {
        $ward = User::query()->find($this->wardId);

        if ($ward) {
            HandleGuardianLossAction::new()->execute($ward, $this->trigger);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Could not finish pausing a minor\'s therapies for a missing guardian.', [
            'ward' => $this->wardId,
            'trigger' => $this->trigger,
            'exception' => $exception->getMessage(),
        ]);
    }
}
