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

    // TT-3.2f-b/SCRUM-319: this specific join-cycle's own full grant/raise history -- see
    // VideoSessionSpeakingGrant/VideoSessionHandRaise's own comments for why each is shaped the
    // way it is (append-only audit vs. mutable current-state).
    public function speakingGrants()
    {
        return $this->hasMany(VideoSessionSpeakingGrant::class);
    }

    public function handRaises()
    {
        return $this->hasMany(VideoSessionHandRaise::class);
    }

    public function currentSpeakingGrant(): ?VideoSessionSpeakingGrant
    {
        return $this->speakingGrants()->whereNull('revoked_at')->latest('granted_at')->first();
    }

    public function currentHandRaise(): ?VideoSessionHandRaise
    {
        return $this->handRaises()->whereNull('lowered_at')->latest('raised_at')->first();
    }
}
