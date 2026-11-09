<?php

namespace App\Listeners;

use App\Models\PushSubscription;
use Illuminate\Auth\Events\Logout;

// SCRUM-399: signing out stops this device being alerted -- the backstop to the page's own unsubscribe,
// matched by the session it was turned on in. (Discovered automatically from app/Listeners.)
class ForgetPushDeviceOnLogout
{
    public function handle(Logout $event): void
    {
        if (! $event->user || ! app()->bound('session')) {
            return;
        }

        rescue(fn () => PushSubscription::query()->where('user_id', $event->user->getAuthIdentifier())
            ->where('session_hash', hash('sha256', (string) session()->getId()))->delete(), report: false);
    }
}
