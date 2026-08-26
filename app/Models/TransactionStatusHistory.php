<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionStatusHistory extends Model
{
    use HasFactory;

    protected $fillable = ['transaction_id', 'status', 'source', 'message'];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }
}
