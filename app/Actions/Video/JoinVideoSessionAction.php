<?php

namespace App\Actions\Video;

use App\Actions\Action;
use App\Actions\Message\EnsureUserCanAccessTherapyContentAction;
use App\Contracts\VideoProviderInterface;
use App\Enums\ConstantsEnum;
use App\Events\VideoSessionStatusChangedEvent;
use App\Exceptions\VideoException;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\User;
use App\Models\VideoSession;
use App\Models\VideoSessionParticipant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

// TT-3.1a/SCRUM-274: the one entry point for joining a Session's video call -- finds or creates
// the current "epoch" (VideoSession row), creates the provider room on first join, then mints
// this specific user's own join credentials.
class JoinVideoSessionAction extends Action
{
    // Returns the provider-specific, JSON-serializable join credentials -- passed straight
    // through to the frontend's active provider SDK, never inspected or reshaped here -- plus one
    // `participant` key of our own (TT-3.2f-j/SCRUM-327) describing what this joiner may do. Neither
    // provider's credentials use that key, and both client SDK wrappers read only their own.
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

        $videoSession = $this->currentOrNewVideoSessionAndJoin($session, $user, $provider);

        // SCRUM-331: never an owner in a GroupTherapy call -- a Daily owner's browser can change ANY
        // participant's permissions client-side, bypassing the server-enforced minor/anonymity
        // speaking rules (SCRUM-324). Every moderation action (remove, end, grant/revoke) goes
        // through our server with the API key, so group counsellors lose nothing they use; they
        // all remain equal peers. Unchanged for 1:1 Therapy (two participants, no such rules).
        $isOwner = ! $session->for instanceof GroupTherapy
            && (bool) ($user->counsellor && $session->for->isCounsellor($user->counsellor));

        $receiveOnly = $this->isReceiveOnly($session, $user);
        $isMasked = $this->isMaskedGroupParticipant($session, $user);

        $credentials = $provider->createParticipantCredentials(
            $videoSession,
            $user,
            $this->displayNameFor($session, $user),
            isOwner: $isOwner,
            receiveOnly: $receiveOnly,
            canSendVideo: ! $isMasked,
        );

        return [
            ...$credentials,
            'participant' => $this->participantStateFor($session, $user, $videoSession, $receiveOnly, $isMasked),
        ];
    }

    // TT-3.2f-j/SCRUM-327: display-only -- the provider token minted above is what actually
    // enforces the send capabilities, and every moderation endpoint re-authorizes on its own. Lets
    // the call UI start in the right state (receive-only member, audio-only anonymous creator) and
    // lets a counsellor who joins mid-call see the hands and speakers that already exist, since the
    // broadcasts that built them were sent before they subscribed.
    private function participantStateFor(Session $session, User $user, VideoSession $videoSession, bool $receiveOnly, bool $isMasked): array
    {
        $isGroup = $session->for instanceof GroupTherapy;
        $canModerate = $isGroup && $session->for->isCounsellorUser($user);
        // Only ever asked of a receive-only member, i.e. a GroupTherapy member.
        $isMinorMember = $receiveOnly && $session->for->memberIsMinor($user);

        return [
            'receiveOnly' => $receiveOnly,
            'canSendAudio' => ! $receiveOnly,
            'canSendVideo' => ! $receiveOnly && ! $isMasked,
            'isAnonymous' => $isMasked,
            // Mirrors RaiseHandAction's own refusal for a minor (SCRUM-324) -- a hand that could
            // never be granted is never offered. isMinorMember is the reason, for the UI's copy;
            // it describes only the joiner themselves.
            'canRaiseHand' => $receiveOnly && ! $isMinorMember,
            'isMinorMember' => $isMinorMember,
            'canModerate' => $canModerate,
            'speakingGrantIdleTimeout' => (int) config('video.speaking_grant_idle_timeout'),
            'raisedHandUserIds' => $canModerate ? $this->liveUserIdsWith($videoSession, 'handRaises', 'lowered_at') : [],
            'speakerUserIds' => $canModerate ? $this->liveUserIdsWith($videoSession, 'speakingGrants', 'revoked_at') : [],
        ];
    }

    // Users whose LIVE join row in this call holds an open hand-raise/speaking grant -- the latest
    // open row per user, the same row VideoSession::activeParticipantFor() treats as current, so a
    // stale row left open by a reconnect (SCRUM-330) never lists someone who can't actually speak.
    private function liveUserIdsWith(VideoSession $videoSession, string $relation, string $closedColumn): array
    {
        return $videoSession->participants()
            ->where('participant_type', User::class)
            ->whereNull('left_at')
            ->withExists([$relation.' as has_open' => fn ($query) => $query->whereNull($closedColumn)])
            ->orderByDesc('joined_at')
            ->orderByDesc('id')
            ->get()
            ->unique('participant_id')
            ->filter(fn ($row) => $row->has_open)
            ->map(fn ($row) => (int) $row->participant_id)
            ->values()
            ->all();
    }

    // TT-3.2f-d/SCRUM-321: an ordinary GroupTherapy member (not a counsellor, not the creator) is
    // admitted to video but only ever receive-only -- EnsureVideoIsAvailableForSessionAction's own
    // allow-list no longer excludes them entirely, so this is where the distinction actually gets
    // decided, mirroring how $isOwner above is already computed independently of that action
    // rather than returned from it. Always false for 1:1 Therapy (no receive-only concept there)
    // and false for a GroupTherapy's own counsellor/creator (full access, unchanged from TT-3.2's
    // own v1 shape).
    private function isReceiveOnly(Session $session, User $user): bool
    {
        if (! $session->for instanceof GroupTherapy) {
            return false;
        }

        return $session->for->isReceiveOnlyVideoParticipant($user);
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
    //
    // TT-3.2f-g/SCRUM-324 review finding: for a GroupTherapy this used to mask only the creator, so
    // an ordinary member who is anonymous (group flag OR their own pivot flag) joined under their
    // REAL name -- shown to every other participant, and sent to the provider. Now mirrors
    // routes/channels.php's own group masking exactly: any non-counsellor anonymous participant.
    private function displayNameFor(Session $session, User $user): string
    {
        $therapy = $session->for;

        if ($therapy instanceof GroupTherapy) {
            return $this->isMaskedGroupParticipant($session, $user)
                ? ConstantsEnum::anonymousUserLabel->value
                : $user->name;
        }

        $isAnonymousAddedbyUser = $therapy->addedby_type === User::class
            && $therapy->addedby->is($user)
            && $therapy->isAnonymousFor($user);

        return $isAnonymousAddedbyUser ? ConstantsEnum::anonymousUserLabel->value : $user->name;
    }

    // Any non-counsellor anonymous participant of a GroupTherapy (group flag OR their own pivot
    // flag) -- masked by name (displayNameFor() above) AND, per SCRUM-332, never given a camera:
    // the creator of an anonymous group joins audio-only, matching the audio-only rule SCRUM-324
    // applies to anonymous members' speaking grants. (Ordinary members join receive-only anyway.)
    // Decided per join: turning a group's anonymity OFF mid-call leaves the creator audio-only
    // until they rejoin -- the safe direction.
    private function isMaskedGroupParticipant(Session $session, User $user): bool
    {
        return $session->for instanceof GroupTherapy
            && ! $session->for->isCounsellorUser($user)
            && $session->for->isAnonymousFor($user);
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
    //
    // TT-3.2f-d/SCRUM-321: this method now ALSO performs the Chime-specific participant-cap check
    // and creates this user's own VideoSessionParticipant row, both inside the SAME lock -- Chime
    // has no native provider-level room-size cap (unlike Daily's own max_participants), so it's
    // enforced here in application code instead. Moving the participant-row creation into this
    // same transaction (it used to happen after this method returned) is what actually closes the
    // race: two near-simultaneous joins against an already-full room could otherwise both read
    // "N active, room for one more" before either's insert committed.
    private function currentOrNewVideoSessionAndJoin(Session $session, User $user, VideoProviderInterface $provider): VideoSession
    {
        // wasNewlyCreated tracked outside the transaction closure deliberately -- this app's
        // queue connections all have after_commit=false (config/queue.php), so a ShouldBroadcast
        // event dispatched FROM INSIDE the transaction below could be picked up by a queue worker
        // before the transaction actually commits. Dispatching only after DB::transaction()
        // returns guarantees the row is visible to whatever reads it back when the job runs.
        $wasNewlyCreated = false;

        $videoSession = DB::transaction(function () use ($session, $user, $provider, &$wasNewlyCreated) {
            Session::query()->lockForUpdate()->find($session->id);

            $videoSession = VideoSession::query()
                ->where('session_id', $session->id)
                ->whereNull('ended_at')
                ->first();

            if (! $videoSession) {
                // Created (and persisted) BEFORE calling the provider -- both DailyVideoProvider
                // and ChimeVideoProvider derive their own room/meeting id from $videoSession->id,
                // so it must already exist.
                $videoSession = VideoSession::query()->create([
                    'session_id' => $session->id,
                    'provider' => config('video.provider'),
                    'started_at' => now(),
                ]);

                // TT-3.1c/SCRUM-276 QA finding: this call was unguarded, so a provider HTTP
                // failure (e.g. a real 4xx from Daily's own API) propagated as a raw
                // Illuminate\Http\Client\RequestException all the way to the frontend -- its
                // getCode() equals the upstream HTTP status (not 500), so
                // ResolvesExceptionResponse::messageFor()'s 500-only masking never applied,
                // leaking the provider's own raw response body (e.g. Daily's literal
                // {"error":"authorization-header-error",...}) straight to the end user. Caught
                // and rethrown as our own controlled, generic VideoException instead.
                try {
                    $room = $provider->createRoom($videoSession);
                } catch (Throwable $exception) {
                    Log::warning('Video provider failed to create a room for a session.', [
                        'session_id' => $session->id,
                        'exception' => $exception->getMessage(),
                    ]);

                    throw new VideoException('Unable to start the video call right now. Please try again shortly.', 502);
                }

                $videoSession->update([
                    'provider_room_id' => $room['room_id'],
                    'provider_meta' => $room['meta'],
                ]);

                $wasNewlyCreated = true;
            }

            $this->ensureChimeGroupTherapyCapacity($session, $videoSession);

            VideoSessionParticipant::query()->create([
                'video_session_id' => $videoSession->id,
                'participant_type' => User::class,
                'participant_id' => $user->id,
                'joined_at' => now(),
            ]);

            return $videoSession;
        });

        if ($wasNewlyCreated) {
            VideoSessionStatusChangedEvent::dispatch($videoSession, 'started');
        }

        return $videoSession;
    }

    // TT-3.2f-d/SCRUM-321: Daily gets its own room-size ceiling for free via `max_participants`
    // at createRoom() time (DailyVideoProvider::maxParticipantsFor()) -- Chime has no equivalent
    // provider-level parameter, so the same ConstantsEnum::groupTherapyVideoMaxParticipants ceiling
    // is enforced here in application code instead, against a live count of still-active
    // participants for THIS video session epoch. A no-op for 1:1 Therapy (Daily/Chime alike --
    // the counsellor/client pair is already bounded by EnsureVideoIsAvailableForSessionAction's
    // own allow-list, never reaches a size where this matters) and for Daily (already capped
    // provider-side, checking again here would just duplicate that ceiling).
    //
    // Review finding, filed as SCRUM-330 (not fixed here): this counts VideoSessionParticipant
    // AUDIT ROWS (whereNull('left_at')), not distinct users -- a person who disconnects without
    // an explicit leave (refresh, crash, network drop) leaves a stale row still counted as
    // "active" until something marks it left_at, and a reconnect via the frontend's own
    // handleDisconnected() just creates ANOTHER row via a fresh join() call (TT-3.1d's own
    // design) rather than reconciling the old one. A real cap-slot-leak risk over a long-running
    // group call with several reconnects -- accepted as a known, tracked gap, not silently
    // papered over.
    private function ensureChimeGroupTherapyCapacity(Session $session, VideoSession $videoSession): void
    {
        if (! $session->for instanceof GroupTherapy || config('video.provider') !== 'chime') {
            return;
        }

        $activeCount = VideoSessionParticipant::query()
            ->where('video_session_id', $videoSession->id)
            ->whereNull('left_at')
            ->count();

        if ($activeCount >= (int) ConstantsEnum::groupTherapyVideoMaxParticipants->value) {
            throw new VideoException('This video call has reached its maximum number of participants.', 422);
        }
    }
}
