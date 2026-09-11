<?php

namespace App\Actions\Video;

use App\Actions\Action;
use App\Actions\Message\EnsureUserCanAccessTherapyContentAction;
use App\Contracts\VideoProviderInterface;
use App\Enums\ConstantsEnum;
use App\Events\VideoSessionStatusChangedEvent;
use App\Exceptions\VideoException;
use App\Models\Session;
use App\Models\User;
use App\Models\VideoSession;
use App\Models\VideoSessionParticipant;
use Illuminate\Support\Facades\DB;

// TT-3.1a/SCRUM-274: the one entry point for joining a Session's video call -- finds or creates
// the current "epoch" (VideoSession row), creates the provider room on first join, then mints
// this specific user's own join credentials.
class JoinVideoSessionAction extends Action
{
    // Returns the provider-specific, JSON-serializable join credentials -- passed straight
    // through to the frontend's active provider SDK, never inspected or reshaped here.
    public function execute(Session $session, User $user): array
    {
        EnsureVideoIsAvailableForSessionAction::new()->execute($session, $user);

        // TT-3.1b/SCRUM-275: the SAME shared strict-payment-gate check message creation already
        // reuses (EnsureCanSendMessageToForAction) -- deliberately not a second, independent
        // payment check, so PER_THERAPY/PER_SESSION, retainer-org bypass, and billing-suspension
        // all stay in exactly one place. A no-op for anyone but the therapy's own paying client
        // (counsellor, or a co-client with an existing grant, always pass through unaffected) --
        // see that action's own comment for why only `addedby` is ever gated here (TT-3.1 is 1:1
        // Therapy only, already enforced above by EnsureVideoIsAvailableForSessionAction).
        if (! EnsureUserCanAccessTherapyContentAction::new()->execute($session->for, $user, $session)) {
            throw new VideoException('Payment is required to access video for this session.', 402);
        }

        // Resolved from the container (config('video.provider')-driven binding, see
        // VideoServiceProvider), not a `new` provider class -- keeps this action ignorant of
        // which concrete provider is active, matching this codebase's own Action convention of
        // never taking constructor dependencies.
        $provider = app(VideoProviderInterface::class);

        $videoSession = $this->currentOrNewVideoSession($session, $provider);

        $isOwner = (bool) ($user->counsellor && $session->for->isCounsellor($user->counsellor));

        VideoSessionParticipant::query()->create([
            'video_session_id' => $videoSession->id,
            'participant_type' => User::class,
            'participant_id' => $user->id,
            'joined_at' => now(),
        ]);

        return $provider->createParticipantCredentials($videoSession, $user, $this->displayNameFor($session, $user), $isOwner);
    }

    // Security-review finding (2026-09-11): DailyVideoProvider was sending $user->name straight
    // to Daily's API as the on-screen label every OTHER participant sees -- for an anonymous
    // individual Therapy, that leaked the client's real name to the counsellor over video, exactly
    // the class of bug TT-4.9 already tracks for notifications. Resolving the display identity
    // HERE (not inside each provider adapter) means DailyVideoProvider/ChimeVideoProvider never
    // need to know about Therapy's own anonymity rules at all -- they just receive a name to use.
    // Mirrors TherapyTrait::addedByUserIsMaskedFor()'s own logic (anonymity only ever applies to a
    // User addedby/client, never a counsellor, and never masks someone's view of their own name --
    // moot here since the label is for OTHER participants, never the user themselves).
    private function displayNameFor(Session $session, User $user): string
    {
        $therapy = $session->for;

        $isAnonymousAddedbyUser = $therapy->addedby_type === User::class
            && $therapy->addedby->is($user)
            && $therapy->isAnonymousFor($user);

        return $isAnonymousAddedbyUser ? ConstantsEnum::anonymousUserLabel->value : $user->name;
    }

    // A VideoSession that hasn't ended yet is the current epoch to join -- there is at most one
    // such row per Session at a time.
    //
    // Reviewer finding (2026-09-11): the original find-then-create here had no locking, so two
    // participants joining at nearly the same instant (the normal case for a 1:1 call -- both
    // sides typically click "join" around session start) could both read "no open epoch" before
    // either insert committed, each creating its own VideoSession/provider room -- the two
    // participants would then hold credentials for two different rooms and never see each other,
    // with no error surfaced anywhere. Fixed the same way JoinGroupTherapyAction's own identical
    // concurrent-find-or-create race is fixed: lock before deciding whether to create. There's no
    // VideoSession row guaranteed to exist yet to lock directly, so this locks the one row that
    // always does (the parent Session) to serialize concurrent joins to it.
    //
    // The lock is held through the provider's own createRoom() HTTP/API call, not just the DB
    // write -- deliberately: the whole point is serializing "did anyone else already start
    // creating a room for this epoch", and a two-phase "claim a placeholder row, release the
    // lock, then call the provider" would just move the race to "what if a second caller sees an
    // unready placeholder". A human clicking "join video" is not a high-throughput path, so
    // holding the lock for one external API call's duration is an acceptable, deliberate trade-off
    // here -- do not use this as a pattern for anything actually high-concurrency.
    private function currentOrNewVideoSession(Session $session, VideoProviderInterface $provider): VideoSession
    {
        // wasNewlyCreated tracked outside the transaction closure deliberately -- this app's
        // queue connections all have after_commit=false (config/queue.php), so a ShouldBroadcast
        // event dispatched FROM INSIDE the transaction below could be picked up by a queue worker
        // before the transaction actually commits. Dispatching only after DB::transaction()
        // returns guarantees the row is visible to whatever reads it back when the job runs.
        $wasNewlyCreated = false;

        $videoSession = DB::transaction(function () use ($session, $provider, &$wasNewlyCreated) {
            Session::query()->lockForUpdate()->find($session->id);

            $videoSession = VideoSession::query()
                ->where('session_id', $session->id)
                ->whereNull('ended_at')
                ->first();

            if ($videoSession) {
                return $videoSession;
            }

            // Created (and persisted) BEFORE calling the provider -- both DailyVideoProvider and
            // ChimeVideoProvider derive their own room/meeting id from $videoSession->id, so it
            // must already exist.
            $videoSession = VideoSession::query()->create([
                'session_id' => $session->id,
                'provider' => config('video.provider'),
                'started_at' => now(),
            ]);

            $room = $provider->createRoom($videoSession);

            $videoSession->update([
                'provider_room_id' => $room['room_id'],
                'provider_meta' => $room['meta'],
            ]);

            $wasNewlyCreated = true;

            return $videoSession;
        });

        if ($wasNewlyCreated) {
            VideoSessionStatusChangedEvent::dispatch($videoSession, 'started');
        }

        return $videoSession;
    }
}
