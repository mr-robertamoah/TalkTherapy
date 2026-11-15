<?php

namespace App\Support;

use App\Models\GroupTherapy;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

// SCRUM-381 / SCRUM-345: a group's members as a counsellor refers to them -- by a key that isn't their
// user id ("creator", or their membership row's id), so an anonymous member can't be linked across
// groups or requests. Extracted from GetPausedGroupMembersAction (SCRUM-32 reuses it for safety flags,
// former members included).
final class GroupMemberKeys
{
    public const CREATOR = 'creator';

    // Current members (and a user-created group's creator). The `users` relation is loaded once and
    // kept, so displayNameOf() reads each membership row from it.
    /** @return Collection<int, array{key: string, user: User, left: bool}> */
    public static function current(GroupTherapy $group): Collection
    {
        // Without a pivot: the creator, pushed onto the loaded relation by
        // GetGroupTherapyPaymentRosterAction -- added below as CREATOR instead.
        $members = $group->loadMissing('users')->users
            ->filter(fn (User $user) => $user->pivot?->id)
            ->map(fn (User $user) => ['key' => (string) $user->pivot->id, 'user' => $user, 'left' => false]);

        return self::withCreator($group, $members);
    }

    // SCRUM-32: current and former members (who left -- their membership row is kept, stamped left_at).
    // SCRUM-445: joinedAt too (null for a creator without a membership row).
    /** @return Collection<int, array{key: string, user: User, left: bool, leftAt: ?Carbon, joinedAt: ?Carbon}> */
    public static function all(GroupTherapy $group): Collection
    {
        $members = $group->allMemberships()->get()
            ->filter(fn (User $user) => $user->pivot?->id)
            ->map(fn (User $user) => [
                'key' => (string) $user->pivot->id, 'user' => $user, 'left' => $user->pivot->left_at !== null,
                'leftAt' => $user->pivot->left_at ? Carbon::parse($user->pivot->left_at) : null,
                'joinedAt' => $user->pivot->created_at ? Carbon::parse($user->pivot->created_at) : null,
            ]);

        return self::withCreator($group, $members);
    }

    // SCRUM-32/445: how a counsellor of the group sees each of $members (entries from current() or all()), in order --
    // named as the group shows them (displayNameOf(): an anonymous member stays "Anonymous"); someone who left and is
    // masked is told apart by when they left, never by name (security review: picking the wrong person is a harm of its
    // own). A label that repeats is numbered.
    /** @return Collection<int, string> */
    public static function labels(GroupTherapy $group, Collection $members): Collection
    {
        $labels = $members->values()->map(fn (array $member) => ($member['left'] ?? false) && $group->isMaskedToOthers($member['user'])
            ? 'Former member, left '.($member['leftAt']?->format('M Y') ?? 'earlier')
            : $group->displayNameOf($member['user']));
        $counts = $labels->countBy();
        $seen = [];

        return $labels->map(function (string $label) use ($counts, &$seen) {
            if ($counts[$label] > 1) {
                $seen[$label] = ($seen[$label] ?? 0) + 1;
                $label .= " ({$seen[$label]})";
            }

            return $label;
        });
    }

    // The member $key stands for, or null.
    public static function resolve(GroupTherapy $group, ?string $key, bool $includeFormer = false): ?User
    {
        if (! $key) {
            return null;
        }

        return ($includeFormer ? self::all($group) : self::current($group))->firstWhere('key', $key)['user'] ?? null;
    }

    private static function withCreator(GroupTherapy $group, Collection $members): Collection
    {
        if ($group->addedby_type === User::class && $group->addedby) {
            $members->prepend(['key' => self::CREATOR, 'user' => $group->addedby, 'left' => false, 'leftAt' => null, 'joinedAt' => null]);
        }

        return $members->values();
    }
}
