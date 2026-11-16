<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// SCRUM-456: a Google account (by a keyed hash of its id, `sub`) that may not sign up or be connected again -- the
// account it was connected to was closed by an administrator. Closing clears users.google_id at once (SCRUM-452), so
// without this the same Google account could simply sign up afresh. The raw id is never stored.
class BlockedGoogleIdentity extends Model
{
    public const REASON_CLOSED_BY_ADMIN = 'closed_by_admin';

    public $timestamps = false;

    protected $fillable = ['sub_hash', 'blocked_at', 'reason'];

    protected $casts = [
        'blocked_at' => 'datetime',
    ];

    // Keyed with the app key, so the table alone can't be matched against known Google ids; the label keeps this HMAC
    // apart from any other use of the key. $key: a previous app key (APP_PREVIOUS_KEYS), else the current one.
    public static function hashFor(string $sub, ?string $key = null): string
    {
        return hash_hmac('sha256', 'blocked-google:'.$sub, $key ?? (string) config('app.key'));
    }

    // Every hash this id may be stored under: the current key's first, then each previous key's.
    public static function hashesFor(string $sub): array
    {
        return array_values(array_unique([
            static::hashFor($sub),
            ...array_map(fn ($key) => static::hashFor($sub, (string) $key), array_filter((array) config('app.previous_keys', []))),
        ]));
    }

    public static function block(string $sub, ?string $reason = null): void
    {
        // Already blocked (the same Google account on an earlier closed account): kept as it was.
        static::query()->insertOrIgnore([
            'sub_hash' => static::hashFor($sub),
            'blocked_at' => now(),
            'reason' => $reason,
        ]);
    }

    // Security review (M1): survives an APP_KEY rotation -- a block written under a previous key still counts, and is
    // written again under the current key, so it outlives that key's removal from APP_PREVIOUS_KEYS too.
    public static function isBlocked(string $sub): bool
    {
        $hashes = static::hashesFor($sub);
        $found = static::query()->whereIn('sub_hash', $hashes)->get(['sub_hash', 'blocked_at', 'reason']);

        if ($found->isEmpty()) {
            return false;
        }

        if (! $found->contains('sub_hash', $hashes[0])) {
            $old = $found->first();
            static::query()->insertOrIgnore(['sub_hash' => $hashes[0], 'blocked_at' => $old->blocked_at, 'reason' => $old->reason]);
        }

        return true;
    }

    // Lifting a block by hand (support, after a review): every hash the id may be stored under.
    public static function lift(string $sub): int
    {
        return static::query()->whereIn('sub_hash', static::hashesFor($sub))->delete();
    }
}
