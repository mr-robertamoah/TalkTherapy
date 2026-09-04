<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrganizationPaymentInstrument extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id', 'authorization_code', 'masked_card_number', 'card_type',
        'bank', 'exp_month', 'exp_year', 'currency', 'pending_credit_amount',
    ];

    protected $casts = [
        'pending_credit_amount' => 'integer',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
