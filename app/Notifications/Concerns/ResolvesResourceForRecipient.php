<?php

namespace App\Notifications\Concerns;

use App\Models\Counsellor;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// SCRUM-372: a resource inside a notification is resolved as its RECIPIENT, never as whoever's
// request happens to be running -- per-viewer fields (payment status, anonymity masking) would
// otherwise carry the acting user's view to everyone notified if the notification were ever sent
// synchronously. Queued, there is no current user at all, which hid the problem.
trait ResolvesResourceForRecipient
{
    protected function resolveFor(JsonResource $resource, object $notifiable): array
    {
        $viewer = match (true) {
            $notifiable instanceof User => $notifiable,
            $notifiable instanceof Counsellor => $notifiable->user,
            default => null,
        };

        // A fresh request, not the current one with its user swapped: resources cache viewer facts
        // on the request (UserMiniResource's viewerIsAdmin), so a reused request carried one
        // recipient's -- or the acting user's -- view to the next. Nested resources serialise later
        // through the container's request, so the fresh one is bound there while the payload is
        // encoded, then the original is put back.
        $previous = app('request');
        // From APP_URL: binding a request also re-roots the URL generator, and '/' made every asset
        // URL in the payload (avatars) http://localhost/...
        $request = Request::create(config('app.url'));
        app()->instance('request', $request);
        // After binding: rebinding the request resets its user resolver to the signed-in user.
        $request->setUserResolver(fn () => $viewer);

        try {
            return json_decode(json_encode($resource->resolve($request)), true);
        } finally {
            app()->instance('request', $previous);
        }
    }
}
