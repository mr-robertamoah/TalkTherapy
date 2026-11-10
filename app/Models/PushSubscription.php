<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// SCRUM-399: one of a person's devices for web push. Never sent to the browser beyond its id, label and
// dates; the endpoint and keys are encrypted at rest.
class PushSubscription extends Model
{
    protected $fillable = ['user_id', 'endpoint', 'endpoint_hash', 'public_key', 'auth_token', 'content_encoding', 'label', 'session_hash', 'last_used_at'];

    protected $hidden = ['endpoint', 'endpoint_hash', 'public_key', 'auth_token', 'session_hash'];

    protected $casts = [
        'endpoint' => 'encrypted',
        'public_key' => 'encrypted',
        'auth_token' => 'encrypted',
        'last_used_at' => 'datetime',
    ];

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    // SCRUM-399: whether the server may post to $endpoint -- https, at one of the push services in
    // help.push.endpoint_hosts. Checked when a device is saved and again before sending.
    public static function endpointAllowed(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (($parts['scheme'] ?? null) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['port'])) {
            return false;
        }

        $host = strtolower($parts['host']);
        foreach ((array) config('help.push.endpoint_hosts') as $allowed) {
            $allowed = strtolower((string) $allowed);
            if (str_starts_with($allowed, '*.') ? str_ends_with($host, substr($allowed, 1)) : $host === $allowed) {
                return true;
            }
        }

        return false;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
