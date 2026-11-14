<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\DB;

// SCRUM-423: this account's other sessions may just have ended (password changed, two-factor turned on or off, a
// reset). Queued (security review): the change never waits on Reverb, and a second's delay is harmless. Every open page of the account asks the server whether it is still signed in
// (route session.check); the ended ones go to the sign-in page and close their live connection. Carries nothing: the
// server's answer to each page is what decides, never this event -- a page that ignores it is still refused on its
// next request (EndStaleSessions).
class SessionCheckRequestedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(private int $userId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("users.{$this->userId}")];
    }

    public function broadcastWith(): array
    {
        return [];
    }

    public function broadcastAs(): string
    {
        return 'session.check';
    }

    // Once the change is committed and the response sent (code review: the session that made the change saves its new
    // fingerprint as the response goes out -- a check from its own tabs before that would read the old one and sign
    // it out, losing the recovery codes on screen). Never failing anything: it's a convenience.
    public static function after(int $userId): void
    {
        DB::afterCommit(fn () => defer(fn () => rescue(fn () => self::dispatch($userId), report: true)));
    }
}
