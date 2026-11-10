<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrganizationCounsellorCompensation extends Model
{
    use HasFactory;

    protected $table = 'organization_counsellor_compensations';

    protected $fillable = [
        'organization_counsellor_id',
        'set_by_id',
        'type',
        'amount',
        'currency',
        'percentage',
        'basis',
        'negotiated_rate_amount',
        'effective_from',
    ];

    protected $casts = [
        'amount' => 'integer',
        'percentage' => 'integer',
        'negotiated_rate_amount' => 'integer',
        'effective_from' => 'datetime',
    ];

    public function organizationCounsellor()
    {
        return $this->belongsTo(OrganizationCounsellor::class);
    }

    public function setBy()
    {
        return $this->belongsTo(User::class, 'set_by_id');
    }
}
