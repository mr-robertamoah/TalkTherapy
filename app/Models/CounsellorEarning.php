<?php

namespace App\Models;

use App\Enums\CounsellorEarningStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CounsellorEarning extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id', 'counsellor_id', 'organization_invoice_line_id', 'counsellor_payout_id',
        'gross_amount', 'fee_amount', 'net_amount', 'currency', 'share_basis', 'share_percentage',
        'status',
    ];

    protected $casts = [
        'gross_amount' => 'integer',
        'fee_amount' => 'integer',
        'net_amount' => 'integer',
    ];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function counsellor()
    {
        return $this->belongsTo(Counsellor::class);
    }

    // TT-7.3b-e/SCRUM-236: set only for an earning generated from a settled retainer invoice --
    // this row's own idempotency key for that branch (see GenerateCounsellorEarningsAction's
    // generateForSettledInvoice()), null for the other two generation branches.
    public function organizationInvoiceLine()
    {
        return $this->belongsTo(OrganizationInvoiceLine::class);
    }

    public function statusHistories()
    {
        return $this->hasMany(CounsellorEarningStatusHistory::class);
    }

    // TT-7.6c/SCRUM-227: which payout batch last claimed this row -- null until a payout is
    // triggered, reassigned (not cleared) if a failed payout returns this row to `pending` and a
    // later payout re-claims it.
    public function payout()
    {
        return $this->belongsTo(CounsellorPayout::class, 'counsellor_payout_id');
    }

    public function isPending(): bool
    {
        return $this->status === CounsellorEarningStatusEnum::pending->value;
    }

    public function scopeWherePending($query)
    {
        return $query->where('status', CounsellorEarningStatusEnum::pending->value);
    }
}
