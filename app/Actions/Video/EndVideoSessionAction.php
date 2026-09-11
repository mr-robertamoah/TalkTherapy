<?php

namespace App\Actions\Video;

use App\Actions\Action;
use App\Contracts\VideoProviderInterface;
use App\Events\VideoSessionStatusChangedEvent;
use App\Exceptions\VideoException;
use App\Models\Session;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

// TT-3.1a/SCRUM-274: ends the whole room for every participant -- distinct from
// LeaveVideoSessionAction's self-leave. A no-op if there is no currently-open video epoch (safe
// to call defensively, e.g. from wherever the underlying Session itself later gets marked
// held/failed/abandoned, so "ending the Session also ends any active video call" -- a product
// requirement from this ticket's own scoping -- has exactly one implementation to call into,
// rather than being duplicated at each of that action's own call sites).
//
// TT-3.1b/SCRUM-275: $user is optional and, when given, must be a session participant --
// security-review finding on TT-3.1a (this took no $user at all, so anyone holding a Session
// object could end another pair's call). Left nullable rather than required so a future internal
// call site (the "Session itself gets marked held/failed/abandoned" case referenced above, which
// has no single acting user) can still call this without a check that would never apply to it.
class EndVideoSessionAction extends Action
{
    public function execute(Session $session, ?User $user = null): void
    {
        if ($user && $session->isNotParticipant($user)) {
            throw new VideoException('You are not allowed to end this session\'s video call.', 422);
        }

        $videoSession = $session->videoSessions()->whereNull('ended_at')->first();

        if (! $videoSession) {
            return;
        }

        $videoSession->participants()->whereNull('left_at')->update(['left_at' => now()]);

        // Best-effort -- a provider-side failure here must never block marking the room ended
        // locally (see VideoProviderInterface::endRoom()'s own contract). TT-3.1c/SCRUM-276 QA
        // finding: this comment previously described intent the code didn't actually implement --
        // there was no try/catch, so a provider failure DID propagate and block the local
        // ended_at update below. Fixed to match the stated contract.
        try {
            app(VideoProviderInterface::class)->endRoom($videoSession);
        } catch (Throwable $exception) {
            Log::warning('Provider-side video room teardown failed; local state ends anyway.', [
                'video_session_id' => $videoSession->id,
                'exception' => $exception->getMessage(),
            ]);
        }

        $videoSession->update(['ended_at' => now()]);

        VideoSessionStatusChangedEvent::dispatch($videoSession, 'ended');
    }
}
