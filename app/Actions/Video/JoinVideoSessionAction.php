<?php

namespace App\Actions\Video;

use App\Actions\Action;
use App\Actions\Message\EnsureUserCanAccessTherapyContentAction;
use App\Actions\Suspension\EnsureTherapyCounsellorIsAvailableAction;
use App\Contracts\VideoProviderInterface;
use App\Enums\ConstantsEnum;
use App\Events\VideoSessionStatusChangedEvent;
use App\Exceptions\AppException;
use App\Exceptions\VideoException;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\User;
use App\Models\VideoSession;
use App\Models\VideoSessionParticipant;
use App\Support\VideoJoinLock;
use Illuminate\Contracts\Cache\LockTimeoutException;
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

        // SCRUM-359
        EnsureTherapyCounsellorIsAvailableAction::new()->execute($user, $session->for);

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

        // SCRUM-378: held from opening the join row through minting the token, so the removal retry
        // (RetryVideoParticipantRemovalJob) can't eject this person between its "rejoined?" check and
        // its provider call.
        try {
            return VideoJoinLock::run($session->id, $user->id, fn () => $this->join($session, $user, $provider));
        } catch (LockTimeoutException) {
            throw new VideoException('Still joining you to the video call. Please try again in a moment.', 409);
        }
    }

    private function join(Session $session, User $user, VideoProviderInterface $provider): array
    {
        [$videoSession, $participantRow] = $this->currentOrNewVideoSessionAndJoin($session, $user, $provider);

        // SCRUM-331: never an owner in a GroupTherapy call -- a Daily owner's browser can change ANY
        // participant's permissions client-side, bypassing the server-enforced minor/anonymity
        // speaking rules (SCRUM-324). Every moderation action (remove, end, grant/revoke) goes
        // through our server with the API key, so group counsellors lose nothing they use; they
        // all remain equal peers. Unchanged for 1:1 Therapy (two participants, no such rules).
        $isOwner = ! $session->for instanceof GroupTherapy
            && (bool) ($user->counsellor && $session->for->isCounsellor($user->counsellor));

        $receiveOnly = $this->isReceiveOnly($session, $user);
        // SCRUM-332/379: the camera rule lives on the therapy (GroupTherapy::allowsCameraFor()).
        $withholdsCamera = ! $session->for->allowsCameraFor($user);

        // SCRUM-335: guarded like createRoom() below -- an HTTP client error's code is the upstream
        // status and its message the provider's response body, neither of which may reach the user.
        // The participant row opened above is closed again: this person never joined, so they must
        // not count toward the call's capacity or look "in the call" to moderation.
        try {
            $credentials = $provider->createParticipantCredentials(
                $videoSession,
                $user,
                $session->for->displayNameOf($user),
                isOwner: $isOwner,
                receiveOnly: $receiveOnly,
                canSendVideo: ! $withholdsCamera,
            );
        } catch (Throwable $exception) {
            $participantRow->update(['left_at' => now()]);

            if ($exception instanceof AppException) {
                throw $exception;
            }

            Log::warning('Video provider failed to create participant credentials for a session.', [
                'session_id' => $session->id,
                'exception' => $exception->getMessage(),
            ]);

            throw new VideoException('Unable to start the video call right now. Please try again shortly.', 502);
        }

        return [
            ...$credentials,
            'participant' => $this->participantStateFor($session, $user, $videoSession, $receiveOnly, $withholdsCamera),
        ];
    }

    // TT-3.2f-j/SCRUM-327: display-only -- the provider token minted above is what actually
    // enforces the send capabilities, and every moderation endpoint re-authorizes on its own. Lets
    // the call UI start in the right state (receive-only member, audio-only anonymous creator) and
    // lets a counsellor who joins mid-call see the hands and speakers that already exist, since the
    // broadcasts that built them were sent before they subscribed.
    private function participantStateFor(Session $session, User $user, VideoSession $videoSession, bool $receiveOnly, bool $withholdsCamera): array
    {
        $isGroup = $session->for instanceof GroupTherapy;
        $canModerate = $isGroup && $session->for->isCounsellorUser($user);
        // Only ever asked of a receive-only member, i.e. a GroupTherapy member.
        $isMinorMember = $receiveOnly && $session->for->memberIsMinor($user);

        return [
            'receiveOnly' => $receiveOnly,
            'canSendAudio' => ! $receiveOnly,
            'canSendVideo' => ! $receiveOnly && ! $withholdsCamera,
            'isAnonymous' => $withholdsCamera,
            // Mirrors RaiseHandAction's own refusal for a minor (SCRUM-324) -- a hand that could
            // never be granted is never offered. isMinorMember is the reason, for the UI's copy;
            // it describes only the joiner themselves.
            'canRaiseHand' => $receiveOnly && ! $isMinorMember,
            'isMinorMember' => $isMinorMember,
            'canModerate' => $canModerate,
            'speakingGrantIdleTimeout' => (int) config('video.speaking_grant_idle_timeout'),
            // SCRUM-357: participant keys, never user ids -- the same identity the provider and the
            // broadcasts use. `key` is the joiner's own, so their browser can recognise itself.
            'key' => $videoSession->participantKeyFor($user->id),
            'raisedHandKeys' => $canModerate ? $this->liveKeysWith($videoSession, 'handRaises', 'lowered_at') : [],
            'speakerKeys' => $canModerate ? $this->liveKeysWith($videoSession, 'speakingGrants', 'revoked_at') : [],
        ];
    }

    // Users whose LIVE join row in this call holds an open hand-raise/speaking grant -- the latest
    // open row per user, the same row VideoSession::activeParticipantFor() treats as current, so a
    // stale row left open by a reconnect (SCRUM-330) never lists someone who can't actually speak.
    private function liveKeysWith(VideoSession $videoSession, string $relation, string $closedColumn): array
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
            ->map(fn ($row) => $videoSession->participantKeyFor((int) $row->participant_id))
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
    // Returns the video session and the participant row opened for this join.
    private function currentOrNewVideoSessionAndJoin(Session $session, User $user, VideoProviderInterface $provider): array
    {
        // wasNewlyCreated tracked outside the transaction closure deliberately -- this app's
        // queue connections all have after_commit=false (config/queue.php), so a ShouldBroadcast
        // event dispatched FROM INSIDE the transaction below could be picked up by a queue worker
        // before the transaction actually commits. Dispatching only after DB::transaction()
        // returns guarantees the row is visible to whatever reads it back when the job runs.
        $wasNewlyCreated = false;

        $participantRow = null;

        $videoSession = DB::transaction(function () use ($session, $user, $provider, &$wasNewlyCreated, &$participantRow) {
            VideoJoinLock::lockSessionRows($session);

            // SCRUM-334: re-read the therapy under the lock. Making a group anonymous takes the same
            // lock (GroupTherapyService::updateGroupTherapy), so the masking decided after this
            // matches what that change saw: it refuses while this join's row exists, or this join
            // sees the group as anonymous.
            // A plain re-fetch: refresh() would also reload every relation already loaded on it (a
            // group's whole member list) while holding the lock.
            $session->setRelation('for', $session->for->fresh());

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

            $this->ensureChimeGroupTherapyCapacity($session, $videoSession, $user);

            $participantRow = VideoSessionParticipant::query()->create([
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

        return [$videoSession, $participantRow];
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
    // SCRUM-330: counts PEOPLE, not join rows. A disconnect without an explicit leave (refresh, crash,
    // network drop) leaves its row open, and the reconnect opens another (TT-3.1d), so counting rows
    // filled the 25 places with the same few people over a long call. The joiner themselves isn't
    // counted either: someone reconnecting already holds a place and is never turned away from it.
    private function ensureChimeGroupTherapyCapacity(Session $session, VideoSession $videoSession, User $user): void
    {
        if (! $session->for instanceof GroupTherapy || config('video.provider') !== 'chime') {
            return;
        }

        $activeCount = VideoSessionParticipant::query()
            ->where('video_session_id', $videoSession->id)
            ->where('participant_type', User::class)
            ->whereNull('left_at')
            ->where('participant_id', '!=', $user->id)
            ->distinct()
            ->count('participant_id');

        if ($activeCount >= (int) ConstantsEnum::groupTherapyVideoMaxParticipants->value) {
            throw new VideoException('This video call has reached its maximum number of participants.', 422);
        }
    }
}
