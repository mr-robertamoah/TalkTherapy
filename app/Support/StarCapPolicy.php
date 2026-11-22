<?php

namespace App\Support;

use App\Enums\StarSourceEnum;
use App\Models\Counsellor;
use App\Models\Star;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Support\Carbon;

// TT-7.11b/SCRUM-471: the daily star caps (the user's decision). Per counsellor per UTC day: at most N stars from
// each rule (held sessions, help chats, held discussions, posts), and at most N session stars -- held sessions and
// help chats together -- with the same client (the star's starredby). The numbers are SettingsService's
// (config('recognition.cap') unless a super admin set them). Post-interaction stars have their own limits (SCRUM-472).
// Called by AwardCounsellorStarAction with the counsellor's row locked, so two awards can't both slip under a cap.
final class StarCapPolicy
{
    private const CAP_KEYS = [
        'session_held' => 'session',
        'help_chat_held' => 'help_chat',
        'discussion_held' => 'discussion',
        'post_published' => 'post',
    ];

    private const PER_CLIENT_SOURCES = [StarSourceEnum::sessionHeld, StarSourceEnum::helpChatHeld];

    public static function allows(Counsellor $counsellor, StarSourceEnum $source, ?User $client): bool
    {
        $capKey = self::CAP_KEYS[$source->value] ?? null;

        if ($capKey === null) {
            return true;
        }

        $caps = SettingsService::new()->getRecognitionCaps();

        if (self::today($counsellor)->where('source', $source->value)->count() >= $caps[$capKey]) {
            return false;
        }

        if (! $client || ! in_array($source, self::PER_CLIENT_SOURCES, true)) {
            return true;
        }

        return self::today($counsellor)
            ->whereIn('source', array_map(fn (StarSourceEnum $s) => $s->value, self::PER_CLIENT_SOURCES))
            ->where('starredby_type', $client->getMorphClass())
            ->where('starredby_id', $client->getKey())
            ->count() < $caps['session_per_client'];
    }

    // The counsellor's stars from the current UTC day. created_at is stored in the app's timezone, so the day's bounds
    // are converted to it.
    private static function today(Counsellor $counsellor)
    {
        $from = Carbon::now('UTC')->startOfDay()->setTimezone(config('app.timezone'));

        return Star::query()
            ->where('starred_type', $counsellor->getMorphClass())
            ->where('starred_id', $counsellor->getKey())
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $from->copy()->addDay());
    }
}
