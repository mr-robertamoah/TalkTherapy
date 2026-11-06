<?php

namespace App\Models;

use App\Enums\CounsellorVerificationEventEnum;
use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

// SCRUM-31: see the counsellor_verification_events migration. In app code, written only by
// RecordCounsellorVerificationEventAction (the backfill migration and the seeder write it directly).
class CounsellorVerificationEvent extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'event' => CounsellorVerificationEventEnum::class,
        'license_ids' => 'array',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function counsellor()
    {
        return $this->belongsTo(Counsellor::class)->withTrashed();
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id')->withTrashed();
    }
}
