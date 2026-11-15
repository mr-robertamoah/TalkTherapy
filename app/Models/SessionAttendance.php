<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// SCRUM-445: one client's or member's attendance at an in-person session. Written only by RecordSessionAttendanceAction
// (the marking counsellor is always the signed-in one, never taken from input). A client or member sees only their own
// row, never who marked it; counsellors and administrators see the register (GetSessionAttendanceRegisterAction).
// Attendance never moves money.
class SessionAttendance extends Model
{
    protected $fillable = ['session_id', 'user_id', 'status', 'marked_by_counsellor_id', 'marked_at'];

    protected $casts = ['marked_at' => 'datetime'];

    public function session()
    {
        return $this->belongsTo(Session::class)->withTrashed();
    }

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function markedBy()
    {
        return $this->belongsTo(Counsellor::class, 'marked_by_counsellor_id')->withTrashed();
    }

    // What the person themselves is shown -- never the counsellor who marked it.
    public function toOwnView(): array
    {
        return ['status' => $this->status, 'markedAt' => $this->marked_at?->toIso8601String()];
    }
}
