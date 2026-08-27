<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrganizationMemberBillingConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_member_id',
        'mode',
        'per',
        'include_group_therapies',
        'effective_from',
    ];

    protected $casts = [
        'include_group_therapies' => 'boolean',
        'effective_from' => 'datetime',
    ];

    public function organizationMember()
    {
        return $this->belongsTo(OrganizationMember::class);
    }
}
