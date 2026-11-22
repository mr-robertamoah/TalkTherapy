<?php

namespace App\Support;

use App\Enums\SessionAttendanceStatusEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\HelpRequest;
use App\Models\Message;
use App\Models\Session;
use App\Models\SessionAttendance;
use App\Models\Therapy;
use App\Models\User;
use App\Models\VideoSessionParticipant;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

// TT-7.11b/SCRUM-471: proof that both sides took part in a held session (the user's decision) -- a session marked held
// is not enough for a star. The counsellor side is the therapy's counsellor (a group's counsellors), as their profile
// or their user; the client side is the therapy's client (a group's members, current or former). Any one of:
//  - messages in the session's chat from both sides, sent between its start and its end;
//  - both sides joined its video call (a video_session_participants row with joined_at for each);
//  - an in-person attendance register with a client or member marked present (the counsellor marks it).
// A help chat (helpChatQualifies) needs messages from both sides, at least config('recognition.help_chat_min_minutes')
// between being answered and ending, and must not have been ended by the counsellor's suspension or revoked
// verification (EndCounsellorHelpChatsAction's reasons).
final class SessionParticipation
{
    public const UNQUALIFIED_HELP_ENDINGS = ['suspension', 'revoked'];

    public static function bothSidesTookPart(Session $session): bool
    {
        $for = $session->for;

        if (! $for instanceof Therapy && ! $for instanceof GroupTherapy) {
            return false;
        }

        $counsellorIds = self::counsellorIds($for);
        $counsellorUserIds = Counsellor::withTrashed()->whereKey($counsellorIds)->pluck('user_id')->all();
        $clientUserIds = self::clientUserIds($for);

        if ($counsellorIds === [] || $clientUserIds === []) {
            return false;
        }

        $end = $session->ended_at ?? now();

        return self::messagedOnBothSides($session, $counsellorIds, $counsellorUserIds, $clientUserIds, $session->start_time, $end)
            || self::bothJoinedTheCall($session, $counsellorUserIds, $clientUserIds)
            || SessionAttendance::query()
                ->where('session_id', $session->id)
                ->where('status', SessionAttendanceStatusEnum::present->value)
                ->whereIn('user_id', $clientUserIds)
                ->exists();
    }

    public static function helpChatQualifies(HelpRequest $request): bool
    {
        $session = $request->session;

        if (! $session || ! $request->counsellor_id || ! $request->accepted_at || ! $request->ended_at
            || in_array($request->ended_by, self::UNQUALIFIED_HELP_ENDINGS, true)) {
            return false;
        }

        if ($request->accepted_at->diffInMinutes($request->ended_at) < (int) config('recognition.help_chat_min_minutes')) {
            return false;
        }

        $counsellorUserId = Counsellor::withTrashed()->whereKey($request->counsellor_id)->value('user_id');

        return self::messagedOnBothSides(
            $session, [$request->counsellor_id], array_filter([$counsellorUserId]), [$request->user_id],
            // No lower bound: the chat's messages only exist from the moment it was answered (the person's opening note
            // is written then).
            null, $request->ended_at,
        );
    }

    // The client a held 1:1 session (or help chat) was with -- what the per-client daily cap counts on. A group has
    // none.
    public static function clientOf(Session $session): ?User
    {
        $for = $session->for;

        return $for instanceof Therapy && $for->addedby instanceof User ? $for->addedby : null;
    }

    private static function counsellorIds(Therapy|GroupTherapy $for): array
    {
        if ($for instanceof Therapy) {
            return array_filter([$for->counsellor_id]);
        }

        return $for->counsellors()->pluck('counsellors.id')
            ->push($for->addedby_type === Counsellor::class ? $for->addedby_id : null)
            ->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    private static function clientUserIds(Therapy|GroupTherapy $for): array
    {
        if ($for instanceof Therapy) {
            return $for->addedby_type === User::class ? [(int) $for->addedby_id] : [];
        }

        return $for->allMemberships()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
    }

    private static function messagedOnBothSides(
        Session $session,
        array $counsellorIds,
        array $counsellorUserIds,
        array $clientUserIds,
        ?CarbonInterface $from,
        CarbonInterface $to,
    ): bool {
        $senders = Message::query()
            ->where('for_type', $session->getMorphClass())
            ->where('for_id', $session->id)
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->where('created_at', '<=', $to)
            ->select(['from_type', 'from_id'])
            ->distinct()
            ->get();

        $fromCounsellorSide = $senders->contains(fn (Message $m) => ($m->from_type === Counsellor::class && in_array((int) $m->from_id, $counsellorIds, true))
            || ($m->from_type === User::class && in_array((int) $m->from_id, array_map('intval', $counsellorUserIds), true)));
        $fromClientSide = $senders->contains(fn (Message $m) => $m->from_type === User::class
            && in_array((int) $m->from_id, $clientUserIds, true)
            && ! in_array((int) $m->from_id, array_map('intval', $counsellorUserIds), true));

        return $fromCounsellorSide && $fromClientSide;
    }

    private static function bothJoinedTheCall(Session $session, array $counsellorUserIds, array $clientUserIds): bool
    {
        $joined = VideoSessionParticipant::query()
            ->whereHas('videoSession', fn ($q) => $q->where('session_id', $session->id))
            ->where('participant_type', User::class)
            ->whereNotNull('joined_at')
            ->distinct()
            ->pluck('participant_id')
            ->map(fn ($id) => (int) $id);

        return self::anyIn($joined, $counsellorUserIds) && self::anyIn($joined->diff($counsellorUserIds), $clientUserIds);
    }

    private static function anyIn(Collection $ids, array $within): bool
    {
        return $ids->intersect(array_map('intval', $within))->isNotEmpty();
    }
}
