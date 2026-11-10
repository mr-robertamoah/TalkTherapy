<?php

namespace App\Support;

use App\Models\Session;
use App\Models\User;
use App\Models\VideoSessionParticipant;

// SCRUM-357: how a person is identified to everyone ELSE in a video call -- the provider's own
// participant identity (Daily `user_id`, Chime `ExternalUserId`, both readable by every client in the
// room), the hand-raise/speaking/removal broadcasts and lists, and the moderation routes. It used to
// be the real user id, which anyone signed in can resolve to a name (and, before SCRUM-357, a date of
// birth) through /api/users -- unmasking an anonymous group member. A keyed hash scoped to the
// therapy SESSION: stable for the whole call (so all of the above agree), useless outside it, and
// unlinkable across sessions.
final class VideoParticipantKey
{
    public static function for(int $sessionId, int $userId): string
    {
        return substr(hash_hmac('sha256', "video-session:{$sessionId}:user:{$userId}", (string) config('app.key')), 0, 24);
    }

    // The user a key belongs to, among people who have actually joined this session's video --
    // null for anything else (an unknown or forged key), which every caller treats exactly like a
    // participant who isn't in the call.
    public static function resolve(Session $session, string $key): ?int
    {
        $userIds = VideoSessionParticipant::query()
            ->whereIn('video_session_id', $session->videoSessions()->select('id'))
            ->where('participant_type', User::class)
            ->distinct()
            ->pluck('participant_id');

        foreach ($userIds as $userId) {
            if (hash_equals(self::for($session->id, (int) $userId), $key)) {
                return (int) $userId;
            }
        }

        return null;
    }
}
