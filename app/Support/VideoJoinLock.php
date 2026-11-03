<?php

namespace App\Support;

use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

// SCRUM-378: one person's join to one session's video, and the background retry of their removal
// (RetryVideoParticipantRemovalJob), never interleave. Without it a rejoin could land between the
// retry's "have they been let back in?" check and its provider eject, and the retry would throw out
// someone a counsellor had just readmitted. Per person and session, so nobody else's join waits.
// It must outlive a slow join: Daily allows 5s to connect + 10s per call, and a first join makes two
// (room, token). 90s leaves room for that and for Chime. Kept in the scheduler's store
// (cache.scheduler_store), which the deploy's cache:clear doesn't flush. That store is the file
// driver, shared by every container on the one host through the bind-mounted storage/; a deployment
// with several app hosts needs SCHEDULER_CACHE_STORE on a shared, lock-capable store (redis/database).
//
// lockSessionRows() is the other half of the video locking: the DB lock on Session rows that a join
// and "make this group anonymous" both take (SCRUM-334), defined once here so the two can't drift.
final class VideoJoinLock
{
    public const SECONDS = 90;

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     *
     * @throws LockTimeoutException
     */
    public static function run(int $sessionId, int $userId, Closure $callback): mixed
    {
        return Cache::store(config('cache.scheduler_store'))
            ->lock("video-join:{$sessionId}:{$userId}", self::SECONDS)
            ->block((int) config('video.join_lock_wait_seconds'), $callback);
    }

    // Locks the Session rows a video join reads its therapy's anonymity under: one session (a join),
    // or every session of a therapy or group (making it anonymous). Call inside a DB transaction.
    public static function lockSessionRows(Session|Therapy|GroupTherapy $subject): void
    {
        $query = $subject instanceof Session
            ? Session::query()->whereKey($subject->getKey())
            : Session::query()->where('for_type', $subject::class)->where('for_id', $subject->getKey());

        $query->lockForUpdate()->get(['id']);
    }
}
