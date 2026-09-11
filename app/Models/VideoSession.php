<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// TT-3.1a/SCRUM-274: one "epoch" of a Session's video capability -- created on first join,
// ended (provider-side) when the underlying Session's video ends. Deliberately holds only coarse
// audit facts, never live/mid-call connection state (see the migration's own comment).
class VideoSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id', 'provider', 'provider_room_id', 'provider_meta', 'started_at', 'ended_at',
    ];

    protected $casts = [
        'provider_meta' => 'array',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function session()
    {
        return $this->belongsTo(Session::class);
    }

    public function participants()
    {
        return $this->hasMany(VideoSessionParticipant::class);
    }

    public function isEnded(): bool
    {
        return ! is_null($this->ended_at);
    }

    // A participant is "currently in the room" if their latest join/leave row for this video
    // session has no left_at yet -- deliberately derived, never a separate persisted flag that
    // could drift from the actual join/leave audit trail.
    public function hasActiveParticipant(User $user): bool
    {
        return $this->participants()
            ->where('participant_type', User::class)
            ->where('participant_id', $user->id)
            ->whereNull('left_at')
            ->exists();
    }
}
