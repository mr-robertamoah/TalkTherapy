<?php

namespace App\Actions\SafeguardingException;

use App\Actions\Action;
use App\Models\GroupTherapy;
use App\Models\Request;
use App\Models\User;
use Illuminate\Support\Collection;

// SCRUM-381 (the user's decision, changing SCRUM-288): a group's counsellors see which members are
// paused for a missing guardian, named as the group shows them (displayNameOf(): an anonymous member
// stays "Anonymous"). Each is identified by a key that is not their user id -- "creator", or their
// membership row's id -- so an anonymous member can't be linked across groups or requests (the
// SCRUM-345 rule); the counsellor sends it back to ask for a safeguarding exception (resolve()).
class GetPausedGroupMembersAction extends Action
{
    public const CREATOR = 'creator';

    /** @return Collection<int, array{key: string, user: User, name: string, exceptionRequested: bool}> */
    public function execute(GroupTherapy $group): Collection
    {
        $members = $this->members($group);

        // One query for the whole group, by the same rule as User::isPausedForMissingGuardian()
        // (User::scopeWherePausedForMissingGuardian(), as FindMinorsWithoutActiveGuardianAction uses).
        $pausedIds = User::query()
            ->whereKey($members->map(fn (array $member) => $member['user']->id)->all())
            ->wherePausedForMissingGuardian()
            ->get(['id', 'dob'])
            ->reject(fn (User $user) => $user->isAdult())
            ->modelKeys();

        $paused = $members->filter(fn (array $member) => in_array($member['user']->id, $pausedIds, true))->values();

        if ($paused->isEmpty()) {
            return $paused;
        }

        $requested = Request::query()
            ->pendingSafeguardingException(for: $group)
            ->get(['id', 'type', 'data'])
            ->map(fn (Request $request) => $request->safeguardingWardId());

        return $paused->map(fn (array $member) => [
            ...$member,
            'name' => $group->displayNameOf($member['user']),
            'exceptionRequested' => $requested->contains($member['user']->id),
        ]);
    }

    // The member $key stands for (paused or not), or null.
    public function resolve(GroupTherapy $group, ?string $key): ?User
    {
        return $key ? $this->members($group)->firstWhere('key', $key)['user'] ?? null : null;
    }

    // Members keyed by their membership row (the pivot id), the creator by CREATOR. The `users`
    // relation is loaded once and kept, so displayNameOf() reads each membership row from it.
    /** @return Collection<int, array{key: string, user: User}> */
    private function members(GroupTherapy $group): Collection
    {
        // Without a pivot: the creator, pushed onto the loaded relation by
        // GetGroupTherapyPaymentRosterAction -- added below as CREATOR instead.
        $members = $group->loadMissing('users')->users
            ->filter(fn (User $user) => $user->pivot?->id)
            ->map(fn (User $user) => ['key' => (string) $user->pivot->id, 'user' => $user]);

        if ($group->addedby_type === User::class && $group->addedby) {
            $members->prepend(['key' => self::CREATOR, 'user' => $group->addedby]);
        }

        return $members->values();
    }
}
