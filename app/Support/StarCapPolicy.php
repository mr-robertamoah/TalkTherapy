<?php

namespace App\Support;

use App\Enums\StarSourceEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Post;
use App\Models\PostInteractionAward;
use App\Models\Session;
use App\Models\Star;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

// TT-7.11b/SCRUM-471: the daily star caps (the user's decision). Per counsellor per UTC day: at most N stars from
// each rule (held sessions, help chats, held discussions, posts), at most N session stars -- held sessions and
// help chats together -- with the same client (the star's starredby), and at most N session stars per group. The
// numbers are SettingsService's (config('recognition.cap') unless a super admin set them). Called by
// AwardCounsellorStarAction with the counsellor's row locked, so two awards can't both slip under a cap.
//
// SCRUM-472: post-interaction stars have their own limits, counted on post_interaction_awards (every award ever made,
// kept when its star is revoked), so unliking and liking again never frees a place: at most N a UTC day per counsellor,
// at most N a UTC day from one client to one counsellor, and at most N ever per post.
final class StarCapPolicy
{
    private const CAP_KEYS = [
        'session_held' => 'session',
        'help_chat_held' => 'help_chat',
        'discussion_held' => 'discussion',
        'post_published' => 'post',
    ];

    private const PER_CLIENT_SOURCES = [StarSourceEnum::sessionHeld, StarSourceEnum::helpChatHeld];

    public static function allows(Counsellor $counsellor, StarSourceEnum $source, ?User $client, ?Model $starreable = null): bool
    {
        if ($source === StarSourceEnum::postInteraction) {
            return self::allowsInteraction($counsellor, $client, $starreable);
        }

        $capKey = self::CAP_KEYS[$source->value] ?? null;

        if ($capKey === null) {
            return true;
        }

        $caps = SettingsService::new()->getRecognitionCaps();

        if (self::today($counsellor)->where('source', $source->value)->count() >= $caps[$capKey]) {
            return false;
        }

        // SCRUM-471 (security re-check): a group session has no single client, so its stand-in is the group -- at most
        // N group-session stars per counsellor per group a day, so one puppet member can't farm a group's sessions.
        if ($source === StarSourceEnum::sessionHeld && $starreable instanceof Session && $starreable->for_type === (new GroupTherapy)->getMorphClass()) {
            return self::today($counsellor)
                ->where('source', $source->value)
                ->where('starreable_type', $starreable->getMorphClass())
                ->whereIn('starreable_id', Session::query()
                    ->select('id')
                    ->where('for_type', $starreable->for_type)
                    ->where('for_id', $starreable->for_id))
                ->count() < $caps['session_per_group'];
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

    private static function allowsInteraction(Counsellor $counsellor, ?User $client, ?Model $post): bool
    {
        if (! $post instanceof Post || ! $client) {
            return false;
        }

        $caps = SettingsService::new()->getRecognitionCaps();
        [$from, $to] = self::todayBounds();
        $today = fn () => PostInteractionAward::query()
            ->where('counsellor_id', $counsellor->getKey())
            ->where('awarded_at', '>=', $from)
            ->where('awarded_at', '<', $to);

        return $today()->count() < $caps['interaction_per_day']
            && $today()->where('user_id', $client->getKey())->count() < $caps['interaction_per_client']
            && PostInteractionAward::query()->where('post_id', $post->getKey())->count() < $caps['interaction_per_post'];
    }

    // The current UTC day's bounds, in the app's timezone (what created_at and awarded_at are stored in).
    private static function todayBounds(): array
    {
        $from = Carbon::now('UTC')->startOfDay()->setTimezone(config('app.timezone'));

        return [$from, $from->copy()->addDay()];
    }

    // The counsellor's stars from the current UTC day. created_at is stored in the app's timezone, so the day's bounds
    // are converted to it.
    private static function today(Counsellor $counsellor)
    {
        [$from, $to] = self::todayBounds();

        return Star::query()
            ->where('starred_type', $counsellor->getMorphClass())
            ->where('starred_id', $counsellor->getKey())
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to);
    }
}
