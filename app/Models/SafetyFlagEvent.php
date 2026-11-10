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

    // TT-4.3c
    public const CLIENT_NOTICE_SENT = 'client_notice_sent';

    public const CLIENT_NOTICE_HELD = 'client_notice_held';

    public const CLIENT_NOTICE_WITHHELD = 'client_notice_withheld';

    public const GUARDIAN_NOTICE_SENT = 'guardian_notice_sent';

    public const GUARDIAN_NOTICE_HELD = 'guardian_notice_held';

    public const GUARDIAN_NOTICE_WITHHELD = 'guardian_notice_withheld';

    public const NOTICES_CANCELLED = 'notices_cancelled';

    public const NOTICE_NO_RECIPIENT = 'notice_no_recipient';

    // Who it shows as having done it, in the history every uninvolved administrator sees: never the
    // counsellor's name (reviews: one place for that rule, next to the wording).
    public function shownBy(): ?string
    {
        return match (true) {
            in_array($this->action, [self::RAISED, self::WITHDRAWN], true), (bool) ($this->data['byCounsellor'] ?? false) => 'the counsellor',
            in_array($this->action, [self::REALERTED, self::FALLBACK_ALERTED, self::NOTICES_CANCELLED], true) => null,
            (bool) ($this->data['automatic'] ?? false) => 'automatically',
            default => $this->actor?->name,
        };
    }

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
            self::CLIENT_NOTICE_SENT => 'Notice sent to the person',
            self::CLIENT_NOTICE_HELD => 'Notice to the person held',
            self::CLIENT_NOTICE_WITHHELD => 'Notice to the person withheld',
            self::GUARDIAN_NOTICE_SENT => 'Notice sent to their guardians',
            self::GUARDIAN_NOTICE_HELD => 'Notice to their guardians held',
            self::GUARDIAN_NOTICE_WITHHELD => 'Notice to their guardians withheld',
            self::NOTICES_CANCELLED => 'Unsent notices cancelled',
            self::NOTICE_NO_RECIPIENT => ($this->data['which'] ?? null) === 'guardians'
                ? 'Notice to their guardians not sent -- no guardian to tell, or they are now an adult'
                : 'Notice to the person not sent -- their account is closed',
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
