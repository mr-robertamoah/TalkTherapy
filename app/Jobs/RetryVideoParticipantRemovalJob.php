<?php

namespace App\Jobs;

use App\Contracts\VideoProviderInterface;
use App\Models\User;
use App\Models\VideoSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

// SCRUM-315: a removed participant is marked left here at once, but if the provider's eject failed
// (a network blip, a rate limit) their media could still be live there. This asks the provider again,
// with backoff. Safe to repeat: both providers' removeParticipant() do nothing for someone already
// gone. Skipped once the call has ended (the room is torn down), or when the person has joined again
// since (an open join row means they were let back in, and must not be thrown out by an old request).
// When every attempt fails, the failed-job alert (AppService::alertAdminsOfFailedJob) tells the admins.
class RetryVideoParticipantRemovalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 4;

    public $backoff = [10, 30, 90];

    public function __construct(private int $videoSessionId, private int $userId)
    {
        //
    }

    public function handle(VideoProviderInterface $provider): void
    {
        $videoSession = VideoSession::query()->find($this->videoSessionId);
        $user = User::withTrashed()->find($this->userId);

        if (! $videoSession || $videoSession->ended_at || ! $user) {
            return;
        }

        // The removal closed every open row of theirs, so an open row now is a later join.
        if ($videoSession->activeParticipantFor($this->userId)) {
            return;
        }

        $provider->removeParticipant($videoSession, $user);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('The video provider never confirmed a participant removal; they may still be in the call.', [
            'video_session_id' => $this->videoSessionId,
            'removed_user_id' => $this->userId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
