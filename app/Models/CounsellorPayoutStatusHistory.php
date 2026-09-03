<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CounsellorPayoutStatusHistory extends Model
{
    use HasFactory;

    protected $fillable = ['counsellor_payout_id', 'status', 'source', 'message'];

    public function counsellorPayout()
    {
        return $this->belongsTo(CounsellorPayout::class);
    }
}
