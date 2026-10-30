<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        return $this->participantRowsFor($user->id)
            ->whereNull('left_at')
            ->exists();
    }

    // TT-3.2f-f/SCRUM-323: the one place a target is resolved for any per-participant moderation
    // action (remove, grant/revoke speaking, raise/lower hand) -- ONLY from this video session's own
    // live participant rows, never a global User lookup (TT-3.2b/SCRUM-309's security-review fix:
    // a nonexistent id and an id that simply isn't in this call must be indistinguishable). Latest
    // join wins if stale open rows exist (see SCRUM-330).
    public function activeParticipantFor(int $userId): ?VideoSessionParticipant
    {
        return $this->participantRowsFor($userId)
            ->whereNull('left_at')
            ->latest('joined_at')
            ->first();
    }

    // TT-3.2f-i/SCRUM-326: every join-cycle row this user has in this video session, open or not --
    // speaking/hand-raise cleanup must cover stale rows too, since a reconnect can leave an older
    // row still open (SCRUM-330).
    public function participantRowsFor(int $userId): HasMany
    {
        return $this->participants()
            ->where('participant_type', User::class)
            ->where('participant_id', $userId);
    }
}
