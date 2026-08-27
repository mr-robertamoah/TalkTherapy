<?php

namespace App\Models;

use App\Enums\OrganizationCounsellorStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
    // any earlier row without ever mutating it (SCRUM-122).
    public function currentCompensation(): ?OrganizationCounsellorCompensation
    {
        return $this->compensations()
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
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
