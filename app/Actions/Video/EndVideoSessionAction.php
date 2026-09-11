<?php

namespace App\Actions\Video;

use App\Actions\Action;
use App\Contracts\VideoProviderInterface;
use App\Events\VideoSessionStatusChangedEvent;
use App\Models\Session;

// TT-3.1a/SCRUM-274: ends the whole room for every participant -- distinct from
// LeaveVideoSessionAction's self-leave. A no-op if there is no currently-open video epoch (safe
// to call defensively, e.g. from wherever the underlying Session itself later gets marked
// held/failed/abandoned, so "ending the Session also ends any active video call" -- a product
// requirement from this ticket's own scoping -- has exactly one implementation to call into,
// rather than being duplicated at each of that action's own call sites).
class EndVideoSessionAction extends Action
{
    public function execute(Session $session): void
    {
        $videoSession = $session->videoSessions()->whereNull('ended_at')->first();

        if (! $videoSession) {
            return;
        }

        $videoSession->participants()->whereNull('left_at')->update(['left_at' => now()]);

        // Best-effort -- a provider-side failure here must never block marking the room ended
        // locally (see VideoProviderInterface::endRoom()'s own contract).
        app(VideoProviderInterface::class)->endRoom($videoSession);

        $videoSession->update(['ended_at' => now()]);

        VideoSessionStatusChangedEvent::dispatch($videoSession, 'ended');
    }
}
