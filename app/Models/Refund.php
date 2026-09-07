<?php

namespace App\Models;

use App\Enums\RefundStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Refund extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id', 'request_id', 'requested_by_id', 'reference', 'amount', 'currency', 'reason', 'status',
    ];

    protected $casts = [
        'amount' => 'integer',
    ];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function request()
    {
        return $this->belongsTo(Request::class);
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function statusHistories()
    {
        return $this->hasMany(RefundStatusHistory::class);
    }

    public function isSuccessful(): bool
    {
        return $this->status === RefundStatusEnum::success->value;
    }

    public function scopeWhereReference($query, string $reference)
    {
        return $query->where('reference', $reference);
    }
}
