<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// TT-3.2f-b/SCRUM-319: current-state, mutable, self-service-clearable (a member lowers their own
// hand) -- deliberately NOT append-only like VideoSessionSpeakingGrant, since raise/lower has no
// history-shaped value to the business the way a grant's audit trail does.
class VideoSessionHandRaise extends Model
{
    use HasFactory;

    protected $fillable = ['video_session_participant_id', 'raised_at', 'lowered_at'];

    protected $casts = [
        'raised_at' => 'datetime',
        'lowered_at' => 'datetime',
    ];

    public function videoSessionParticipant()
    {
        return $this->belongsTo(VideoSessionParticipant::class);
    }

    public function isActive(): bool
    {
        return is_null($this->lowered_at);
    }

    public function scopeWhereActiveFor($query, int $videoSessionParticipantId)
    {
        return $query
            ->where('video_session_participant_id', $videoSessionParticipantId)
            ->whereNull('lowered_at');
    }
}
