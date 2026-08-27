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
        'type',
        'amount',
        'currency',
        'percentage',
        'basis',
        'effective_from',
    ];

    protected $casts = [
        'amount' => 'integer',
        'percentage' => 'integer',
        'effective_from' => 'datetime',
    ];

    public function organizationCounsellor()
    {
        return $this->belongsTo(OrganizationCounsellor::class);
    }
}
