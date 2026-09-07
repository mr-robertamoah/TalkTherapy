<?php

namespace App\Models;

use App\Enums\OrganizationInvoiceStatusEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'billing_suspended_at' => 'datetime',
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

    // TT-7.3b-e/SCRUM-236: the inverse of OrganizationInvoice::organization() -- not previously
    // needed since GetOrganizationRetainerInvoicesAction queries OrganizationInvoice directly, but
    // SCRUM-245's admin billing-suspension view needs each suspended org's own latest failed
    // invoice, hence latestFailedInvoice() below.
    public function invoices()
    {
        return $this->hasMany(OrganizationInvoice::class);
    }

    // TT-7.3b-followup/SCRUM-245: lets the admin billing-suspension list eager-load "what actually
    // needs retrying" per org in one query rather than an N+1 per-row lookup -- same ofMany()
    // mechanism as OrganizationMember::latestBillingConfig()/OrganizationCounsellor::latestCompensation(),
    // extended with ofMany()'s own documented constraint-closure form (`ofMany($column, $closure)`)
    // since this one also needs a `status` filter, not just "latest" -- those two don't use it
    // because neither needed a filter beyond the aggregate itself.
    public function latestFailedInvoice(): HasOne
    {
        return $this->hasOne(OrganizationInvoice::class)
            ->ofMany(['created_at' => 'max'], function ($query) {
                $query->where('status', OrganizationInvoiceStatusEnum::failed->value);
            });
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

    // TT-7.3b-f2/SCRUM-238: mirrors verify()/isVerified()'s own single-current-state-flag
    // precedent -- an org-level billing standing, not a per-session gate. Writers:
    // UpdateOrganizationInvoiceStatusAction (suspends, on a retainer invoice settlement failure)
    // and LiftOrganizationBillingSuspensionAction/SCRUM-245 (resumes, admin-triggered below).
    public function isBillingSuspended(): bool
    {
        return (bool) $this->billing_suspended_at;
    }

    public function isNotBillingSuspended(): bool
    {
        return ! $this->isBillingSuspended();
    }

    public function suspendBilling(?string $reason = null): void
    {
        $this->billing_suspended_at = now()->utc();
        $this->billing_suspension_reason = $reason;
        $this->save();
    }

    // TT-7.3b-followup/SCRUM-245: the "undo" half SCRUM-238 deliberately left unbuilt (no
    // dunning/auto-retry existed yet at the time) -- platform-admin-only (mirrors verify()'s own
    // "a trust decision made by staff, not self-service" precedent), invoked once ops has
    // confirmed the org's payment method is fixed and/or its outstanding invoice settled.
    public function resumeBilling(): void
    {
        $this->billing_suspended_at = null;
        $this->billing_suspension_reason = null;
        $this->save();
    }
}
