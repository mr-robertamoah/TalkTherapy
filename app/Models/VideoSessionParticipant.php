<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// TT-3.1a/SCRUM-274: an audit-log row per join/leave cycle, not a mutable "current status" row --
// see the migration's own comment for why. Polymorphic `participant` mirrors Session's own
// for_type/for_id convention, designed for N participants from day one even though TT-3.1 only
// ever has 2 (architect recommendation, so TT-3.2 only relaxes a business-rule constant).
class VideoSessionParticipant extends Model
{
    use HasFactory;

    protected $fillable = ['video_session_id', 'participant_type', 'participant_id', 'joined_at', 'left_at'];

    protected $casts = [
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
    ];

    public function videoSession()
    {
        return $this->belongsTo(VideoSession::class);
    }

    public function participant()
    {
        return $this->morphTo();
    }

    public function isActive(): bool
    {
        return ! is_null($this->joined_at) && is_null($this->left_at);
    }
}
