<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RefundStatusHistory extends Model
{
    use HasFactory;

    protected $fillable = ['refund_id', 'status', 'source', 'message'];

    public function refund()
    {
        return $this->belongsTo(Refund::class);
    }
}
