<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CounsellorEarningStatusHistory extends Model
{
    use HasFactory;

    protected $fillable = ['counsellor_earning_id', 'status', 'source', 'message'];

    public function counsellorEarning()
    {
        return $this->belongsTo(CounsellorEarning::class);
    }
}
