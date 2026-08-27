<?php

namespace App\Models;

use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'legal_name',
        'registration_number',
        'description',
        'email',
        'phone',
        'logo_id',
        'is_provider',
        'is_consumer',
    ];

    protected $casts = [
        'is_provider' => 'boolean',
        'is_consumer' => 'boolean',
        'verified_at' => 'datetime',
    ];

    public function admins()
    {
        return $this->belongsToMany(User::class, 'organization_admins')
            ->withPivot(['role'])
            ->withTimestamps();
    }

    public function organizationCounsellors()
    {
        return $this->hasMany(OrganizationCounsellor::class);
    }

    public function logo()
    {
        return $this->belongsTo(File::class, 'logo_id');
    }

    public function sentRequests()
    {
        return $this->morphMany(Request::class, 'from');
    }

    public function receivedRequests()
    {
        return $this->morphMany(Request::class, 'to');
    }

    public function requests()
    {
        return $this->morphMany(Request::class, 'for');
    }

    public function isAdministeredBy(User $user): bool
    {
        return $this->admins()->whereKey($user->id)->exists();
    }

    public function isVerified(): bool
    {
        return (bool) $this->verified_at;
    }

    public function isNotVerified(): bool
    {
        return ! $this->isVerified();
    }

    public function verify(): void
    {
        $this->verified_at = now()->utc();
        $this->save();
    }

    public function hasPendingVerificationRequest(): bool
    {
        return $this->sentRequests()
            ->where('type', RequestTypeEnum::organization->value)
            ->where('status', RequestStatusEnum::pending->value)
            ->exists();
    }
}
