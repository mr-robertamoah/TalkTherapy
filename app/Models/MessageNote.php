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
