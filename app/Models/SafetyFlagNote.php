<?php

namespace App\Models;

use App\Enums\SafetyFlagActionEnum;
use Illuminate\Database\Eloquent\Model;

// SCRUM-32 (TT-4.3b): a note, or an action taken, on a safety flag by the administrator handling it.
// The content is encrypted, and cleared (not deleted) when the client's account is anonymised (TT-4.3c).
class SafetyFlagNote extends Model
{
    public const UPDATED_AT = null;

    public const NOTE = 'NOTE';

    public const ACTION = 'ACTION';

    // TT-4.3c (reviews, QA): why a notice was held or withheld -- the person's (P) or the guardians' (G). Kept
    // as notes, so each reason stays with whoever wrote it and none overwrites another.
    public const HOLD_PERSON = 'HOLD_P';

    public const HOLD_GUARDIANS = 'HOLD_G';

    public const WITHHOLD_PERSON = 'WITHHOLD_P';

    public const WITHHOLD_GUARDIANS = 'WITHHOLD_G';

    public static function noticeKind(bool $guardians, bool $hold): string
    {
        return match (true) {
            $guardians && $hold => self::HOLD_GUARDIANS,
            $guardians => self::WITHHOLD_GUARDIANS,
            $hold => self::HOLD_PERSON,
            default => self::WITHHOLD_PERSON,
        };
    }

    protected $guarded = ['id'];

    protected $casts = [
        'content' => 'encrypted',
        'action_type' => SafetyFlagActionEnum::class,
        'created_at' => 'datetime',
    ];

    public function author()
    {
        return $this->belongsTo(User::class, 'author_user_id')->withTrashed();
    }
}
