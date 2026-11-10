<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CounsellorPricing extends Model
{
    use HasFactory;

    protected $fillable = [
        'counsellor_id',
        'therapy_type',
        'session_type',
        'per',
        'amount',
        'currency',
    ];

    protected $casts = [
        'amount' => 'integer',
    ];

    public function counsellor()
    {
        return $this->belongsTo(Counsellor::class);
    }

    // A flat rate applies to every therapy_type/session_type/per combination -- the row itself
    // carries no scope. An override row always fully specifies all three (see
    // EnsureCounsellorPricingDataIsValidAction), so checking any one is equivalent to checking all.
    public function isFlat(): bool
    {
        return is_null($this->therapy_type);
    }
}
