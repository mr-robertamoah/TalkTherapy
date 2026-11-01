<?php

namespace App\Models;

use App\Enums\SuspensionAppealDecisionEnum;
use App\Enums\SuspensionScopeEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

// SCRUM-359: see the suspensions migration. Written only by the actions in app/Actions/Suspension
// and SuspensionService's expiry sweep.
class Suspension extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'scope' => SuspensionScopeEnum::class,
        'appeal_decision' => SuspensionAppealDecisionEnum::class,
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'lifted_at' => 'datetime',
        'end_processed_at' => 'datetime',
        'appealed_at' => 'datetime',
        'appeal_decided_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function report()
    {
        return $this->belongsTo(Report::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function liftedBy()
    {
        return $this->belongsTo(User::class, 'lifted_by')->withTrashed();
    }

    public function appealDecidedBy()
    {
        return $this->belongsTo(User::class, 'appeal_decided_by')->withTrashed();
    }

    // An admin who mustn't handle this suspension (or see its details): one who is part of the report
    // behind it, the suspended person themselves, or their guardian. Not only via the report --
    // which may have been re-pointed since.
    public function involves(User $admin): bool
    {
        return $this->user_id === $admin->id
            || $admin->wardIds()->contains($this->user_id)
            || (bool) $this->report?->involves($admin);
    }

    // In force right now: started, not lifted, not past its end.
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('lifted_at')
            ->where('starts_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    // Suspensions that keep someone from acting as a counsellor: either scope.
    public function scopeCovering(Builder $query, SuspensionScopeEnum $scope): Builder
    {
        return $scope == SuspensionScopeEnum::account
            ? $query->where('scope', SuspensionScopeEnum::account->value)
            : $query->whereIn('scope', SuspensionScopeEnum::values());
    }

    public function isActive(): bool
    {
        return is_null($this->lifted_at)
            && $this->starts_at->lte(now())
            && (is_null($this->ends_at) || $this->ends_at->isFuture());
    }

    public function canBeAppealed(): bool
    {
        return $this->isActive() && is_null($this->appealed_at);
    }

    public function appealIsPending(): bool
    {
        return ! is_null($this->appealed_at) && is_null($this->appeal_decided_at) && $this->isActive();
    }

    // What the suspended person is shown: never the internal note, the report, or who applied it.
    public function presentForPerson(): array
    {
        return [
            'id' => $this->id,
            'scope' => $this->scope->value,
            'scopeLabel' => $this->scope->label(),
            'startsAt' => $this->starts_at,
            'endsAt' => $this->ends_at,
            'reason' => $this->public_reason,
            'canAppeal' => $this->canBeAppealed(),
            'appealedAt' => $this->appealed_at,
            'appealDecision' => $this->appeal_decision?->value,
            // SCRUM-363: their payouts are held -- the page says so.
            'isCounsellor' => (bool) $this->user?->counsellor,
        ];
    }
}
