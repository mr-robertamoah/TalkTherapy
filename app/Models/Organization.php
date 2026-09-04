<?php

namespace App\Models;

use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
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
        'self_apply_enabled',
    ];

    protected $casts = [
        'is_provider' => 'boolean',
        'is_consumer' => 'boolean',
        'self_apply_enabled' => 'boolean',
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

    public function members()
    {
        return $this->hasMany(OrganizationMember::class);
    }

    // TT-7.3b-a/SCRUM-231: at most one row (unique FK) -- what a future pay-per-use/retainer
    // charge (TT-7.3b-c/-e) charges against via PaystackClient::chargeAuthorization().
    public function paymentInstrument()
    {
        return $this->hasOne(OrganizationPaymentInstrument::class);
    }

    // SCRUM-182/TT-10.4: tagged fileables pivot, same pattern as Counsellor::avatarFile()/
    // coverFile() (TT-10.2) -- withPivotValue (not the similarly-named, nonexistent
    // wherePivotValue) is what actually constrains reads AND auto-populates the tag column on
    // attach()/sync(). logo_id/logoFile() coexist for now; dropping the FK column is deferred.
    public function logoFile(): MorphToMany
    {
        return $this->morphToMany(File::class, 'fileable', 'fileables')
            ->withPivotValue('tag', 'logo')
            ->withTimestamps();
    }

    public function getLogoAttribute(): ?File
    {
        return $this->logoFile->first();
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
