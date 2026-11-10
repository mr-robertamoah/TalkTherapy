<?php

namespace App\Models;

use App\Enums\OrganizationCounsellorStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OrganizationCounsellor extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'counsellor_id',
        'status',
        'source',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function counsellor()
    {
        return $this->belongsTo(Counsellor::class);
    }

    public function compensations()
    {
        return $this->hasMany(OrganizationCounsellorCompensation::class);
    }

    // Latest by effective_from, tie-broken by id -- the most recently agreed terms supersede
    // any earlier row without ever mutating it (SCRUM-122). An eager-loadable relation (not just
    // a query on compensations()) so a paginated list of affiliations can load this without N+1
    // (SCRUM-159).
    public function latestCompensation(): HasOne
    {
        return $this->hasOne(OrganizationCounsellorCompensation::class)
            ->ofMany(['effective_from' => 'max', 'id' => 'max']);
    }

    // Unlike the old orderByDesc()->first() form, this is now a cached relation -- if you write
    // a new compensation row and then call this again on the same instance, call refresh() (or
    // unset($this->latestCompensation)) first, or you'll get the stale cached value.
    public function currentCompensation(): ?OrganizationCounsellorCompensation
    {
        return $this->latestCompensation;
    }

    public function hasCompensation(): bool
    {
        return $this->compensations()->exists();
    }

    public function isActive(): bool
    {
        return $this->status === OrganizationCounsellorStatusEnum::active->value;
    }

    public function isPending(): bool
    {
        return $this->status === OrganizationCounsellorStatusEnum::pending->value;
    }

    public function activate(): void
    {
        $this->status = OrganizationCounsellorStatusEnum::active->value;
        $this->save();
    }
}
