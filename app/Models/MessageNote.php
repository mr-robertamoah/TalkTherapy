<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MessageNote extends Model
{
    use HasFactory,
        SoftDeletes;

    // counsellor_id is fillable at the model layer, but the controller/action must derive it
    // server-side from the authenticated counsellor -- never from client-supplied input, matching
    // SessionNote's identical rule (SCRUM-196 security finding).
    protected $fillable = ['content', 'message_id', 'counsellor_id'];

    // SCRUM-437: a counsellor's private note about a client -- encrypted at rest (existing ones by the
    // 2026_12_05_100000 migration). Nothing queries by its content.
    protected $casts = ['content' => 'encrypted'];

    public function message()
    {
        // withTrashed: the message (or its counsellor below) may have since been soft-deleted;
        // the FK itself only actually goes null on a force-delete (see the migration's
        // nullOnDelete comment), not a soft one.
        return $this->belongsTo(Message::class)->withTrashed();
    }

    public function counsellor()
    {
        return $this->belongsTo(Counsellor::class)->withTrashed();
    }
}
