<?php

namespace App\Http\Controllers;

use App\Actions\Video\EndVideoSessionAction;
use App\Actions\Video\EnsureCanModerateSpeakingPermissionAction;
use App\Actions\Video\EnsureVideoIsAvailableForSessionAction;
use App\Actions\Video\GetVideoParticipantDisplayNameAction;
use App\Actions\Video\GrantSpeakingPermissionAction;
use App\Actions\Video\JoinVideoSessionAction;
use App\Actions\Video\LeaveVideoSessionAction;
use App\Actions\Video\LowerHandAction;
use App\Actions\Video\RaiseHandAction;
use App\Actions\Video\RecordSpeakingActivityAction;
use App\Actions\Video\RemoveParticipantFromVideoSessionAction;
use App\Actions\Video\RevokeSpeakingPermissionAction;
use App\Exceptions\VideoConsentRequiredException;
use App\Exceptions\VideoException;
use App\Models\Session;
use App\Support\FailureResponse;
use App\Support\VideoParticipantKey;
use Illuminate\Http\Request;
use Throwable;

// TT-3.1b/SCRUM-275: the first HTTP-reachable surface for TT-3.1's video backend -- TT-3.1a
// deliberately shipped none, so every route here is gated by the strict-payment-gate check
// (JoinVideoSessionAction) and the participant-only checks (Leave/EndVideoSessionAction) from
// their very first day, never briefly reachable without them.
class VideoSessionController extends Controller
{
    // SCRUM-380: each route's refusal for someone who isn't in the session -- the action's own
    // constant, so the two can't drift (EnsureVideoIsAvailableForSessionAction, LeaveVideoSessionAction,
    // EndVideoSessionAction, RemoveParticipantFromVideoSessionAction,
    // EnsureCanModerateSpeakingPermissionAction, RaiseHandAction).
    private const NOT_ALLOWED_TO_JOIN = EnsureVideoIsAvailableForSessionAction::NOT_ALLOWED_TO_JOIN;

    private const NOT_ALLOWED_TO_LEAVE = LeaveVideoSessionAction::NOT_ALLOWED;

    private const NOT_ALLOWED_TO_END = EndVideoSessionAction::NOT_ALLOWED;

    private const NOT_ALLOWED_TO_REMOVE = RemoveParticipantFromVideoSessionAction::COUNSELLOR_ONLY;

    private const NOT_ALLOWED_TO_MODERATE_SPEAKING = EnsureCanModerateSpeakingPermissionAction::COUNSELLOR_ONLY;

    private const NOT_ALLOWED_TO_RAISE_HAND = RaiseHandAction::JOIN_FIRST;

    // $request->user() only, never a client-supplied id -- security-review requirement carried
    // over from TT-3.1a's own review (impersonating another participant here would mint that
    // user's own join credentials for whoever asked).
    public function join(Request $request)
    {
        try {
            $credentials = JoinVideoSessionAction::new()->execute($this->session($request, self::NOT_ALLOWED_TO_JOIN), $request->user());

            return response()->json($credentials);
        } catch (Throwable $th) {
            return $this->failure($th);
        }
    }

    public function leave(Request $request)
    {
        try {
            LeaveVideoSessionAction::new()->execute($this->session($request, self::NOT_ALLOWED_TO_LEAVE), $request->user());

            return response()->json(['message' => 'Left the video call.']);
        } catch (Throwable $th) {
            return $this->failure($th);
        }
    }

    public function end(Request $request)
    {
        try {
            EndVideoSessionAction::new()->execute($this->session($request, self::NOT_ALLOWED_TO_END), $request->user());

            return response()->json(['message' => 'Video call ended.']);
        } catch (Throwable $th) {
            return $this->failure($th);
        }
    }

    // SCRUM-334: the name the call UI shows for another participant's tile, by their participant key
    // -- never the provider-side name, which that participant can change from their own browser. See
    // GetVideoParticipantDisplayNameAction for who may ask and why every refusal looks the same.
    public function participant(Request $request)
    {
        try {
            // SCRUM-379: an unknown session gets the same 404 as every other refusal here.
            $session = Session::find($request->route('sessionId'))
                ?? throw new VideoException(GetVideoParticipantDisplayNameAction::NOT_IN_CALL, 404);

            return response()->json(GetVideoParticipantDisplayNameAction::new()->execute(
                $session,
                $request->user(),
                (string) $request->route('participantKey'),
            ));
        } catch (Throwable $th) {
            return $this->failure($th);
        }
    }

    // TT-3.2b/SCRUM-309: {participantKey} (SCRUM-357) is the target being removed, never the acting user, which stays
    // $request->user() -- the action authorizes against that separately. Resolved only among this
    // session's own video participants (VideoParticipantKey::resolve), never via a global User lookup (security-review finding: doing so
    // let a "target id doesn't exist" 422 be distinguished from a "target exists but isn't in
    // this call" no-op, a system-wide user-id-existence oracle) -- see the action's own comment.
    public function removeParticipant(Request $request)
    {
        try {
            $confirmed = RemoveParticipantFromVideoSessionAction::new()->execute($session = $this->participantSession($request, self::NOT_ALLOWED_TO_REMOVE), $request->user(), $this->targetUserId($request, $session));

            // SCRUM-315: tells the counsellor when the video service hasn't confirmed it yet.
            return response()->json([
                'message' => $confirmed
                    ? 'Participant removed from the video call.'
                    : 'Participant removed here, but the video service has not confirmed it yet. Retrying -- they may stay visible for a short while.',
                'providerConfirmed' => $confirmed,
            ]);
        } catch (Throwable $th) {
            return $this->failure($th);
        }
    }

    // TT-3.2f-f/SCRUM-323: raise/lower are always self-service ($request->user() only); grant/revoke
    // take the target as a bare {participantKey}, same reasoning as removeParticipant() above.
    public function raiseHand(Request $request)
    {
        try {
            RaiseHandAction::new()->execute($this->participantSession($request, self::NOT_ALLOWED_TO_RAISE_HAND), $request->user());

            return response()->json(['message' => 'Hand raised.']);
        } catch (Throwable $th) {
            return $this->failure($th);
        }
    }

    public function lowerHand(Request $request)
    {
        try {
            // SCRUM-380: lowering is a silent no-op for anyone not in the call, so an unknown session
            // gets the same answer.
            if ($session = Session::find($request->route('sessionId'))) {
                LowerHandAction::new()->execute($session, $request->user());
            }

            return response()->json(['message' => 'Hand lowered.']);
        } catch (Throwable $th) {
            return $this->failure($th);
        }
    }

    public function grantSpeaking(Request $request)
    {
        try {
            GrantSpeakingPermissionAction::new()->execute($session = $this->participantSession($request, self::NOT_ALLOWED_TO_MODERATE_SPEAKING), $request->user(), $this->targetUserId($request, $session));

            return response()->json(['message' => 'Speaking permission granted.']);
        } catch (Throwable $th) {
            return $this->failure($th);
        }
    }

    public function revokeSpeaking(Request $request)
    {
        try {
            RevokeSpeakingPermissionAction::new()->execute($session = $this->participantSession($request, self::NOT_ALLOWED_TO_MODERATE_SPEAKING), $request->user(), $this->targetUserId($request, $session));

            return response()->json(['message' => 'Speaking permission revoked.']);
        } catch (Throwable $th) {
            return $this->failure($th);
        }
    }

    // TT-3.2f-h/SCRUM-325: always self-service ($request->user() only), like raise/lower.
    public function speakingActivity(Request $request)
    {
        try {
            // SCRUM-380: a silent no-op for anyone not in the call (see the action), so an unknown
            // session gets the same answer.
            if ($session = Session::find($request->route('sessionId'))) {
                RecordSpeakingActivityAction::new()->execute($session, $request->user());
            }

            return response()->json(['message' => 'Activity recorded.']);
        } catch (Throwable $th) {
            return $this->failure($th);
        }
    }

    // Every lookup reads $request->route('sessionId') rather than the magic ->sessionId
    // property, matching SessionController's own established convention (SCRUM-116) -- a client
    // could otherwise send {"sessionId": <other id>} in the body and have it resolve instead of
    // the URL's own id.
    // SCRUM-357: the target is a participant key, never a user id (see VideoParticipantKey). A key
    // that matches no one in this session's video resolves to 0 -- a user id that's never in any
    // call -- so it takes the exact same "not in this call" path an absent participant always did.
    private function targetUserId(Request $request, Session $session): int
    {
        return VideoParticipantKey::resolve($session, (string) $request->route('participantKey')) ?? 0;
    }

    // SCRUM-380: an unknown session gets exactly the answer the route gives someone who isn't in a
    // real session (the action's own first refusal), so a session id's existence can't be probed.
    // VideoUnknownSessionTest pins each one against its real-session counterpart.
    private function session(Request $request, string $notAllowed): Session
    {
        return Session::find($request->route('sessionId')) ?? throw new VideoException($notAllowed, 422);
    }

    // SCRUM-380: remove, grant/revoke and raise-hand refuse an outsider differently by session type
    // (a 1:1 says "only for group calls", a group "only a counsellor..."), which told an outsider the
    // session exists and what kind it is. Anyone not in the session, or a session that doesn't
    // exist, gets the group refusal. Participants reach the action unchanged.
    private function participantSession(Request $request, string $notAllowed): Session
    {
        $session = Session::find($request->route('sessionId'));

        if (! $session || $session->isNotParticipant($request->user())) {
            throw new VideoException($notAllowed, 422);
        }

        return $session;
    }

    private function failure(Throwable $th)
    {
        $status = $this->statusFor($th);
        $message = $this->messageFor($th, $status);

        // TT-3.1e-f/SCRUM-285: lets the frontend show a specific "guardian consent needed" banner
        // instead of a generic error+retry, without string-matching $message.
        // SCRUM-288: plus e.g. guardian_required, when the refusal has one.
        return response()->json(FailureResponse::withCode([
            'message' => $message,
            'videoConsentRequired' => $th instanceof VideoConsentRequiredException,
        ], $th), $status);
    }
}
