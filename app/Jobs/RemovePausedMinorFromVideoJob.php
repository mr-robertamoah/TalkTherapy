<?php

namespace App\Jobs;

use App\Actions\Video\EjectVideoParticipantAction;
use App\Actions\Video\EndVideoSessionAction;
use App\Actions\Video\GetLivePersonVideoParticipationsAction;
use App\Models\GroupTherapy;
use App\Models\User;
use App\Support\VideoJoinLock;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

// SCRUM-288: takes a minor paused for a missing guardian out of one session's live call -- the
// whole 1:1 room is ended (its counsellor is read-only too), in a group only they are ejected.
// HandleGuardianLossAction runs remove() at once and queues this when it couldn't (their join lock
// was busy, or something failed). Each run re-checks under the person's join lock that they are
// still paused and still in that call, so a retry never removes someone who has since been let back.
class RemovePausedMinorFromVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 4;

    public $backoff = [10, 30, 90];

    public function __construct(private int $sessionId, private int $wardId) {}

    public function handle(): void
    {
        $ward = User::query()->find($this->wardId);

        if ($ward) {
            // A busy lock (LockTimeoutException) or a failure throws: the queue tries again.
            self::remove($ward, $this->sessionId);
        }
    }

    // Whether they were taken out of a call.
    public static function remove(User $ward, int $sessionId): bool
    {
        return VideoJoinLock::run($sessionId, $ward->id, function () use ($ward, $sessionId) {
            if (! $ward->isPausedForMissingGuardian(fresh: true)) {
                return false;
            }

            $live = GetLivePersonVideoParticipationsAction::new()->execute($ward, $sessionId)->first();
            $session = $live?->videoSession?->session;

            if (! $live || ! $session) {
                return false;
            }

            if ($session->for instanceof GroupTherapy) {
                EjectVideoParticipantAction::new()->execute($live->videoSession, $live);
            } else {
                EndVideoSessionAction::new()->execute($session);
            }

            return true;
        });
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Could not take a paused minor out of a video call; they may still be in it.', [
            'session_id' => $this->sessionId,
            'ward' => $this->wardId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
