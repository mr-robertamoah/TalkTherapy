<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

// SCRUM-32: one step in a safety flag's history -- never changed or deleted (the database refuses it
// too). `data` is encrypted: notes and reasons are sensitive.
class SafetyFlagEvent extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    public const RAISED = 'raised';

    public const ACKNOWLEDGED = 'acknowledged';

    public const REASSIGNED = 'reassigned';

    // TT-4.3b
    public const NOTE = 'note';

    public const ACTION_TAKEN = 'action_taken';

    public const RESOLVED = 'resolved';

    public const WITHDRAWN = 'withdrawn';

    public const REALERTED = 'realerted';

    public const FALLBACK_ALERTED = 'fallback_alerted';

    // How each step reads in the history the administrators see (never its content).
    public function label(): string
    {
        return match ($this->action) {
            self::RAISED => 'Raised by the counsellor',
            self::ACKNOWLEDGED => 'Acknowledged',
            self::REASSIGNED => 'Handed on',
            self::NOTE => 'Note added',
            self::ACTION_TAKEN => 'Action recorded',
            self::RESOLVED => 'Resolved',
            self::WITHDRAWN => 'Withdrawn by the counsellor',
            self::REALERTED => 'Super administrators alerted again (nobody had acknowledged it)',
            self::FALLBACK_ALERTED => 'Fallback mailbox alerted (no administrator could be told)',
            default => $this->action,
        };
    }

    protected $guarded = ['id'];

    protected $casts = [
        'data' => 'encrypted:array',
        'created_at' => 'datetime',
    ];

    public function flag()
    {
        return $this->belongsTo(SafetyFlag::class, 'safety_flag_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id')->withTrashed();
    }
}
