<?php

namespace App\Models;

use App\Enums\OrganizationMemberStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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

    // Latest by effective_from, tie-broken by id -- mirrors OrganizationCounsellor::currentCompensation().
    public function currentBillingConfig(): ?OrganizationMemberBillingConfig
    {
        return $this->billingConfigs()
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }
}
