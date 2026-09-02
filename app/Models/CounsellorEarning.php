<?php

namespace App\Models;

use App\Enums\CounsellorEarningStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CounsellorEarning extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id', 'counsellor_id', 'gross_amount', 'fee_amount', 'net_amount',
        'currency', 'share_basis', 'share_percentage', 'status',
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

    public function statusHistories()
    {
        return $this->hasMany(CounsellorEarningStatusHistory::class);
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
