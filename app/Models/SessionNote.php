<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SessionNote extends Model
{
    use HasFactory,
        SoftDeletes;

    // counsellor_id is fillable at the model layer, but TT-2.2b's controller/action must derive
    // it server-side from the authenticated counsellor -- never from client-supplied input, or a
    // counsellor could author a note attributed to someone else (security-engineer review,
    // SCRUM-196).
    protected $fillable = ['content', 'session_id', 'counsellor_id'];

    public function session()
    {
        // withTrashed: see Message::from()/to() -- the session (or its counsellor below) may
        // have since been soft-deleted; the FK itself only actually goes null on a force-delete
        // (see the migration's nullOnDelete comment), not a soft one.
        return $this->belongsTo(Session::class)->withTrashed();
    }

    public function counsellor()
    {
        return $this->belongsTo(Counsellor::class)->withTrashed();
    }
}
