<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// SCRUM-424: a reset of someone's two-factor sign-in, waiting to apply (TwoFactorResets: request, cancel, apply).
class TwoFactorReset extends Model
{
    use HasUlids;

    public const SOURCE_ADMIN = 'admin';

    public const SOURCE_SERVER = 'server';

    // Why a reset stopped without applying.
    public const CANCELLED_BY_LINK = 'link';

    public const CANCELLED_BY_SIGN_IN = 'signed_in';

    public const CANCELLED_FROM_PROFILE = 'profile';

    public const CANCELLED_BY_SELF_CHANGE = 'self_change';

    public const CANCELLED_BY_ADMIN = 'admin';

    public const CANCELLED_ON_SERVER = 'server';

    public const SUPERSEDED = 'superseded'; // two-factor was set up again meanwhile

    public const ALREADY_OFF = 'already_off';

    // Applied at once from the server (--now) while one was waiting -- recorded as applied, not cancelled.
    public const APPLIED_NOW = 'applied_now';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'two_factor_confirmed_at_snapshot' => 'datetime',
            'due_at' => 'datetime',
            'mail_required' => 'boolean',
            'notified_at' => 'datetime',
            'applied_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopePending(Builder $query): void
    {
        $query->whereNull('applied_at')->whereNull('cancelled_at');
    }

    public function isPending(): bool
    {
        return $this->applied_at === null && $this->cancelled_at === null;
    }
}
