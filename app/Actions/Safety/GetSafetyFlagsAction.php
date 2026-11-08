<?php

namespace App\Actions\Safety;

use App\Actions\Action;
use App\Enums\SafetyFlagStatusEnum;
use App\Enums\SafetyFlagUrgencyEnum;
use App\Models\GroupTherapy;
use App\Models\SafetyFlag;
use App\Models\Therapy;
use App\Models\User;
use App\Support\Countries;
use Illuminate\Database\Eloquent\Relations\MorphTo;

// SCRUM-32: the administrators' safety alerts -- open and acknowledged first (the most urgent, then the
// oldest), then the rest. Never a name, the reason, the therapy or the counsellor: those are for the
// handling administrator, through RevealSafetyFlagAction. One the viewer is involved in isn't shown at
// all (nor counted), and there is deliberately no search by person: a name match would tell them who.
class GetSafetyFlagsAction extends Action
{
    private const LIMIT = 200;

    public function execute(User $admin, ?string $status = null): array
    {
        $wardIds = $admin->wardIds();
        $status = SafetyFlagStatusEnum::tryFrom((string) $status);

        $closed = $status && ! in_array($status, [SafetyFlagStatusEnum::open, SafetyFlagStatusEnum::acknowledged], true);

        $flags = self::withInvolvement(SafetyFlag::query())
            ->with('handler')
            ->when($status, fn ($query) => $query->where('status', $status->value), fn ($query) => $query->whereOpen())
            // Open ones: open before handled, the most urgent and then the oldest first. Closed ones: newest
            // first (devops review), so the latest are never cut off.
            ->when(! $closed, fn ($query) => $query
                ->orderByRaw('case status when ? then 0 when ? then 1 else 2 end', [SafetyFlagStatusEnum::open->value, SafetyFlagStatusEnum::acknowledged->value])
                ->orderByRaw('case urgency when ? then 0 when ? then 1 else 2 end', [SafetyFlagUrgencyEnum::imminent->value, SafetyFlagUrgencyEnum::urgent->value])
                ->oldest('id'), fn ($query) => $query->latest('id'))
            ->limit(self::LIMIT)
            ->get()
            ->reject(fn (SafetyFlag $flag) => $flag->involvesAdmin($admin, $wardIds));

        return [
            'flags' => $flags->map(fn (SafetyFlag $flag) => $this->row($flag, $admin))->values()->all(),
        ];
    }

    // For the badge: open flags nobody has acknowledged, that this administrator isn't involved in.
    public function countOpenFor(User $admin): int
    {
        $open = self::withInvolvement(SafetyFlag::query())->where('status', SafetyFlagStatusEnum::open->value)->get();
        if ($open->isEmpty()) {
            return 0;
        }

        $wardIds = $admin->wardIds();

        return $open->reject(fn (SafetyFlag $flag) => $flag->involvesAdmin($admin, $wardIds))->count();
    }

    private function row(SafetyFlag $flag, User $admin): array
    {
        return [
            // The public id, never the sequential one (security review).
            'id' => $flag->public_id,
            'urgency' => $flag->urgency->value,
            'urgencyLabel' => $flag->urgency->label(),
            'status' => $flag->status->value,
            'kind' => $flag->kind->label(),
            'createdAt' => $flag->created_at?->toIso8601String(),
            'acknowledgedAt' => $flag->acknowledged_at?->toIso8601String(),
            'wasMinor' => $flag->was_minor,
            'hadGuardian' => $flag->had_guardian,
            'anonymous' => $flag->anonymous,
            'country' => Countries::nameOf($flag->country_code),
            'handler' => $flag->handler ? ['id' => $flag->handler->id, 'name' => $flag->handler->name] : null,
            'isMine' => $flag->isHandledBy($admin),
        ];
    }

    // For a super administrator handing one on. Every administrator whose account isn't suspended:
    // leaving out the involved ones would tell them who those are (ReassignSafetyFlagAction refuses them
    // with the same answer as any other).
    /** @return array<int, array{id: int, name: string}> */
    public function admins(): array
    {
        return User::query()->whereAdmin()->orderBy('firstName')->limit(200)->get()
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])->all();
    }

    // What involvesAdmin() reads, loaded with the flags rather than once a row (architect review).
    private static function withInvolvement($query)
    {
        return $query->with(['counsellor', 'flaggable' => fn (MorphTo $morphTo) => $morphTo->morphWith([
            Therapy::class => ['addedby', 'counsellor'],
            GroupTherapy::class => ['addedby', 'counsellors'],
        ])]);
    }
}
