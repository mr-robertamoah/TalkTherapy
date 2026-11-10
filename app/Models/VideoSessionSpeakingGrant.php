<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// TT-3.2f-b/SCRUM-319: one grant-cycle per row, never deleted -- mirrors VideoConsent's own
// append-only shape exactly (see that model's own comment for the same reasoning). Anchored to a
// specific VideoSessionParticipant (a join-cycle row), not a bare (video_session_id, user_id)
// pair, so a rejoin never leaves an ambiguous "which cycle does this grant belong to" question.
class VideoSessionSpeakingGrant extends Model
{
    use HasFactory;

    protected $fillable = [
        'video_session_participant_id', 'granted_by_user_id', 'granted_at',
        'revoked_at', 'revoked_by_user_id', 'revocation_reason', 'last_activity_at',
    ];

    protected $casts = [
        'granted_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_activity_at' => 'datetime',
    ];

    public function videoSessionParticipant()
    {
        return $this->belongsTo(VideoSessionParticipant::class);
    }

    public function grantedByUser()
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }

    public function revokedByUser()
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    public function isActive(): bool
    {
        return is_null($this->revoked_at);
    }

    // TT-3.2f-f/h's own "is this participant currently granted?" lookup -- mirrors
    // VideoConsent::scopeWhereValidFor()'s identical shape.
    public function scopeWhereActiveFor($query, int $videoSessionParticipantId)
    {
        return $query
            ->where('video_session_participant_id', $videoSessionParticipantId)
            ->whereNull('revoked_at');
    }
}
