<?php

namespace App\Models;

use App\Enums\OrganizationMemberStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OrganizationMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'user_id',
        'status',
        'source',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === OrganizationMemberStatusEnum::active->value;
    }

    public function billingConfigs()
    {
        return $this->hasMany(OrganizationMemberBillingConfig::class);
    }

    // Latest by effective_from, tie-broken by id -- mirrors OrganizationCounsellor::latestCompensation().
    // An eager-loadable relation so a paginated list of members can load this without N+1 (SCRUM-159).
    public function latestBillingConfig(): HasOne
    {
        return $this->hasOne(OrganizationMemberBillingConfig::class)
            ->ofMany(['effective_from' => 'max', 'id' => 'max']);
    }

    // Unlike the old orderByDesc()->first() form, this is now a cached relation -- if you write
    // a new billing config row and then call this again on the same instance, call refresh()
    // (or unset($this->latestBillingConfig)) first, or you'll get the stale cached value.
    public function currentBillingConfig(): ?OrganizationMemberBillingConfig
    {
        return $this->latestBillingConfig;
    }
}
