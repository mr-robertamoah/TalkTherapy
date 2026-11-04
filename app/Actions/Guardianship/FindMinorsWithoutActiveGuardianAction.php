<?php

namespace App\Actions\Guardianship;

use App\Actions\Action;
use App\Enums\RequestTypeEnum;
use App\Enums\SessionStatusEnum;
use App\Enums\TherapyStatusEnum;
use App\Models\GroupTherapy;
use App\Models\Request;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Support\Collection;

// SCRUM-288 (Q7): read-only inventory of the people whose therapies are paused for a missing
// guardian -- non-administrator accounts with no date of birth or one under 18, with no active
// guardian (Guardianship::scopeActive()). Ids and counts only, nothing personal. Grouped by
// whether a date of birth is known, since the user decides what to do about the no-dob ones.
class FindMinorsWithoutActiveGuardianAction extends Action
{
    public const NO_DOB = 'no_dob';

    public const KNOWN_MINOR = 'known_minor';

    /** @return array{no_dob: Collection, known_minor: Collection} each row: id, openTherapies, groupMemberships, upcomingSessions, pendingProposals */
    public function execute(): array
    {
        $adultFrom = now()->subYears(18)->toDateString();

        $rows = User::query()
            ->where(fn ($q) => $q->whereNull('dob')->orWhere('dob', '>', $adultFrom))
            ->whereDoesntHave('administrator')
            ->whereDoesntHave('guardians', fn ($q) => $q->active())
            ->orderBy('id')
            ->get(['id', 'dob'])
            // isAdult() is the definition; the date filter above only narrows the query.
            ->reject(fn (User $user) => $user->isAdult())
            ->map(fn (User $user) => $this->row($user));

        return [
            self::NO_DOB => $rows->where('noDob', true)->values(),
            self::KNOWN_MINOR => $rows->where('noDob', false)->values(),
        ];
    }

    private function row(User $user): array
    {
        $open = fn ($query) => $query->where('status', '!=', TherapyStatusEnum::ended->value);
        $therapyIds = $open(Therapy::query()->whereUser($user))->pluck('id');
        $groupIds = $open(GroupTherapy::query()->whereUser($user))->pluck('id');

        return [
            'id' => $user->id,
            'noDob' => is_null($user->dob),
            'openTherapies' => $therapyIds->count(),
            'groupMemberships' => $groupIds->count(),
            'upcomingSessions' => Session::query()
                ->where('status', SessionStatusEnum::pending->value)
                ->where('start_time', '>', now())
                ->where(fn ($q) => $q
                    ->where(fn ($q) => $q->where('for_type', Therapy::class)->whereIn('for_id', $therapyIds))
                    ->orWhere(fn ($q) => $q->where('for_type', GroupTherapy::class)->whereIn('for_id', $groupIds)))
                ->count(),
            'pendingProposals' => Request::query()
                ->wherePending()
                ->where('type', RequestTypeEnum::sessionScheduleProposal->value)
                ->where('for_type', Therapy::class)
                ->whereIn('for_id', $therapyIds)
                ->count(),
        ];
    }
}
