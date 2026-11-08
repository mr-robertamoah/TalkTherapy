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
