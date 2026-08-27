<?php

namespace App\Models;

use App\Enums\OrganizationCounsellorStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrganizationCounsellor extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'counsellor_id',
        'status',
        'source',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function counsellor()
    {
        return $this->belongsTo(Counsellor::class);
    }

    public function isActive(): bool
    {
        return $this->status === OrganizationCounsellorStatusEnum::active->value;
    }
}
