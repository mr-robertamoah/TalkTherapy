<?php

namespace App\Actions\SessionAttendance;

use App\Actions\Action;
use App\Actions\Therapy\GetTherapyClientsAction;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\SessionAttendance;
use App\Models\Therapy;
use App\Models\User;
use App\Support\GroupMemberKeys;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

// SCRUM-445: who a session's attendance register is for, as its counsellors refer to them -- by a key, never a user id
// (the SCRUM-345 rule). A 1:1 therapy's client is CLIENT. A group's are everyone who was in it at the time of the
// session -- a user creator, and every membership that overlaps it (joined by its end, not left before its start),
// whether they're still members or not -- plus anyone already marked; never one of its active counsellors. Each person
// once, by their GroupMemberKeys key ("creator", or their membership row's id). Labelled by GroupMemberKeys::labels().
// No authorization: callers check first.
class GetSessionAttendanceRosterAction extends Action
{
    public const CLIENT = 'client';

    /** @return Collection<int, array{key: string, user: User, name: string, attendance: ?SessionAttendance}> */
    public function execute(Session $session): Collection
    {
        $therapy = $session->for;
        $attendances = $session->loadMissing('attendances')->attendances->keyBy('user_id');

        if ($therapy instanceof Therapy) {
            return GetTherapyClientsAction::new()->execute($therapy)
                ->map(fn (User $client) => [
                    'key' => self::CLIENT, 'user' => $client, 'name' => $therapy->displayNameOf($client),
                    'attendance' => $attendances->get($client->id),
                ])
                ->values();
        }

        if (! $therapy instanceof GroupTherapy) {
            return collect();
        }

        $all = GroupMemberKeys::all($therapy);
        $counsellorUserIds = $therapy->activeCounsellors()->pluck('user_id')->all();

        $atTheTime = $all
            ->filter(fn (array $member) => $member['key'] === GroupMemberKeys::CREATOR || (
                $member['joinedAt']?->lessThanOrEqualTo($session->end_time)
                && (! $member['leftAt'] || $member['leftAt']->greaterThan($session->start_time))))
            ->map(fn (array $member) => $member['user']->id);
        $wanted = $atTheTime->merge($attendances->keys())->unique()
            ->reject(fn (int $id) => in_array($id, $counsellorUserIds))
            ->all();

        // One entry per person (a group has one membership row per person -- rejoining reuses it); a creator who also
        // holds a membership row is "creator", which GroupMemberKeys lists first.
        $members = $all
            ->filter(fn (array $member) => in_array($member['user']->id, $wanted))
            ->unique(fn (array $member) => $member['user']->id)
            // A stable order: the creator, then by when they joined (membership row id).
            ->sortBy(fn (array $member) => $member['key'] === GroupMemberKeys::CREATOR ? 0 : (int) $member['key'])
            ->values();

        // The labels read each person's membership and whether they are a counsellor of the group: loaded once.
        $therapy->loadMissing(['users', 'activeCounsellorLinks']);
        (new EloquentCollection($members->pluck('user')->all()))->loadMissing('counsellor');
        $labels = GroupMemberKeys::labels($therapy, $members);

        return $members->map(fn (array $member, int $i) => [
            'key' => $member['key'], 'user' => $member['user'], 'name' => $labels[$i],
            'attendance' => $attendances->get($member['user']->id),
        ]);
    }
}
