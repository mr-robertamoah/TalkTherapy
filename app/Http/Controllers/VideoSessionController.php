<?php

namespace App\Http\Controllers;

use App\Actions\Video\EndVideoSessionAction;
use App\Actions\Video\JoinVideoSessionAction;
use App\Actions\Video\LeaveVideoSessionAction;
use App\Exceptions\SessionException;
use App\Models\Session;
use Illuminate\Http\Request;
use Throwable;

// TT-3.1b/SCRUM-275: the first HTTP-reachable surface for TT-3.1's video backend -- TT-3.1a
// deliberately shipped none, so every route here is gated by the strict-payment-gate check
// (JoinVideoSessionAction) and the participant-only checks (Leave/EndVideoSessionAction) from
// their very first day, never briefly reachable without them.
class VideoSessionController extends Controller
{
    // $request->user() only, never a client-supplied id -- security-review requirement carried
    // over from TT-3.1a's own review (impersonating another participant here would mint that
    // user's own join credentials for whoever asked).
    public function join(Request $request)
    {
        try {
            $credentials = JoinVideoSessionAction::new()->execute($this->session($request), $request->user());

            return response()->json($credentials);
        } catch (Throwable $th) {
            return $this->failure($th);
        }
    }

    public function leave(Request $request)
    {
        try {
            LeaveVideoSessionAction::new()->execute($this->session($request), $request->user());

            return response()->json(['message' => 'Left the video call.']);
        } catch (Throwable $th) {
            return $this->failure($th);
        }
    }

    public function end(Request $request)
    {
        try {
            EndVideoSessionAction::new()->execute($this->session($request), $request->user());

            return response()->json(['message' => 'Video call ended.']);
        } catch (Throwable $th) {
            return $this->failure($th);
        }
    }

    // Every lookup reads $request->route('sessionId') rather than the magic ->sessionId
    // property, matching SessionController's own established convention (SCRUM-116) -- a client
    // could otherwise send {"sessionId": <other id>} in the body and have it resolve instead of
    // the URL's own id.
    private function session(Request $request): Session
    {
        return Session::find($request->route('sessionId')) ?? throw new SessionException('Session was not found.', 422);
    }

    private function failure(Throwable $th)
    {
        $status = $this->statusFor($th);
        $message = $this->messageFor($th, $status);

        return response()->json(['message' => $message], $status);
    }
}
