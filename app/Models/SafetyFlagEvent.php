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
