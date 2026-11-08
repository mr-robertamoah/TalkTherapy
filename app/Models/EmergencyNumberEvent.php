<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

// SCRUM-33: the history of an emergency number -- created, updated or deleted, by whom, and its
// values at that point. Append-only.
class EmergencyNumberEvent extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $fillable = ['emergency_number_id', 'action', 'actor_user_id', 'values'];

    protected $casts = [
        'values' => 'array',
    ];
}
