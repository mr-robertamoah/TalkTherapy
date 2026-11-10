<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CounsellorPayoutAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'counsellor_id', 'type', 'bank_code', 'bank_name', 'account_name',
        'masked_account_number', 'recipient_code', 'currency',
    ];

    public function counsellor()
    {
        return $this->belongsTo(Counsellor::class);
    }
}
