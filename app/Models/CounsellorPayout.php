<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CounsellorPayout extends Model
{
    use HasFactory;

    protected $fillable = [
        'counsellor_id', 'initiated_by_id', 'reference', 'transfer_code', 'amount', 'currency', 'status',
    ];

    protected $casts = [
        'amount' => 'integer',
    ];

    public function counsellor()
    {
        return $this->belongsTo(Counsellor::class);
    }

    public function initiatedBy()
    {
        return $this->belongsTo(User::class, 'initiated_by_id');
    }

    public function earnings()
    {
        return $this->hasMany(CounsellorEarning::class);
    }

    public function statusHistories()
    {
        return $this->hasMany(CounsellorPayoutStatusHistory::class);
    }

    public function scopeWhereReference($query, string $reference)
    {
        return $query->where('reference', $reference);
    }
}
