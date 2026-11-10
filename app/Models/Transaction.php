<?php

namespace App\Models;

use App\Enums\RefundStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\TransactionStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = ['for_type', 'for_id', 'user_id', 'organization_id', 'reference', 'amount', 'currency', 'status'];

    protected $casts = [
        'amount' => 'integer',
    ];

    // TT-7.3b-a/SCRUM-231 (security-engineer finding): `for` (this relation) and `organization()`
    // below are two deliberately distinct, non-overlapping "organization" concepts on this same
    // model -- `for` being an Organization means this transaction's SUBJECT is the org itself
    // (e.g. a payment-instrument-registration charge, InitiateOrganizationPaymentInstrumentRegistrationAction);
    // `organization_id`/`organization()` means an org FINANCED a Therapy/Session/GroupTherapy
    // payment on a member's behalf (TT-7.3a) -- `for` is never an Organization AND
    // `organization_id` set on the same row. A query filtering by one will silently miss the
    // other; don't conflate them.
    public function for()
    {
        return $this->morphTo();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Null when this transaction was paid personally, not through an organization.
    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function statusHistories()
    {
        return $this->hasMany(TransactionStatusHistory::class);
    }

    // TT-7.6b/SCRUM-226: never populated for an org-financed transaction (organization_id set) --
    // that split is TT-7.3b's job, layered on top of this payout mechanism once it exists.
    public function earnings()
    {
        return $this->hasMany(CounsellorEarning::class);
    }

    // TT-7.7a/SCRUM-249: 1:many -- naturally partial-refund-friendly for later, even though this
    // epic is full-refund-only for now.
    public function refunds()
    {
        return $this->hasMany(Refund::class);
    }

    // TT-7.7b/SCRUM-250: lets TherapyResource/SessionResource eager-load "is there a refund
    // request (any status) for this transaction" without a bespoke N+1-prone lookup per row --
    // mirrors Organization::latestFailedInvoice()'s own ofMany-with-constraint shape. `Request` is
    // polymorphic against `for`, so this is the reverse of Request::for()'s own morphTo().
    public function latestRefundRequest(): MorphOne
    {
        return $this->morphOne(Request::class, 'for')
            ->ofMany(['created_at' => 'max'], function ($query) {
                $query->where('type', RequestTypeEnum::refund->value);
            });
    }

    // TT-7.7e/SCRUM-253: lets TherapyResource/SessionResource surface "has this transaction
    // actually been refunded" (distinct from `latestRefundRequest`'s PENDING/REJECTED ask-time
    // state) without a bespoke lookup -- at most one can ever exist per transaction, since
    // EnsureTransactionIsRefundEligibleAction's ACTIVE_REFUND_STATUSES blocks any further refund
    // attempt once one has succeeded.
    public function successfulRefund(): HasOne
    {
        return $this->hasOne(Refund::class)->where('status', RefundStatusEnum::success->value);
    }

    public function isSuccessful(): bool
    {
        return $this->status === TransactionStatusEnum::success->value;
    }

    public function scopeWhereReference($query, string $reference)
    {
        return $query->where('reference', $reference);
    }
}
