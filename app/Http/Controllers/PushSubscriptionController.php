<?php

namespace App\Http\Controllers;

use App\Exceptions\HelpException;
use App\Models\PushSubscription;
use App\Support\FailureResponse;
use App\Support\HelpPush;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

// SCRUM-399: a counsellor's devices for urgent help web push -- their own only. Turned on per device, from the
// dashboard's urgent help card; the browser's permission prompt comes only after they choose it.
class PushSubscriptionController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        // This browser: the page sends its endpoint's SHA-256 (it knows the endpoint; we never send it back).
        $current = (string) $request->input('device', '');

        return response()->json([
            'enabled' => HelpPush::enabled() && $user->counsellor !== null,
            'publicKey' => HelpPush::enabled() ? config('help.push.vapid_public_key') : null,
            'devices' => PushSubscription::query()->where('user_id', $user->id)->latest('id')->get()
                ->map(fn (PushSubscription $device) => [
                    'id' => $device->id,
                    'label' => $device->label ?? 'A device',
                    'addedAt' => $device->created_at?->toIso8601String(),
                    'lastUsedAt' => $device->last_used_at?->toIso8601String(),
                    'current' => $current !== '' && hash_equals($device->endpoint_hash, $current),
                ])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (! HelpPush::enabled() || ! $user->counsellor) {
            return FailureResponse::json(new HelpException('Alerts on this device aren\'t available.', 403));
        }

        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048', function (string $attribute, mixed $value, \Closure $fail) {
                if (! is_string($value) || ! PushSubscription::endpointAllowed($value)) {
                    $fail('This browser\'s push service isn\'t supported.');
                }
            }],
            // A key that can't be used would only fail this device's pushes; refused up front all the same.
            'keys.p256dh' => ['required', 'string', 'max:255', fn (string $attribute, mixed $value, \Closure $fail) => $this->keyOf($value, 65, "\x04") ?? $fail('This browser\'s key isn\'t usable.')],
            'keys.auth' => ['required', 'string', 'max:255', fn (string $attribute, mixed $value, \Closure $fail) => $this->keyOf($value, 16) ?? $fail('This browser\'s key isn\'t usable.')],
            'contentEncoding' => ['nullable', 'in:aes128gcm,aesgcm'],
            'label' => ['nullable', 'string', 'max:120'],
        ]);

        $hash = PushSubscription::hashEndpoint($validated['endpoint']);
        // A device is one person's: signing in as someone else on it moves it to them.
        PushSubscription::query()->updateOrCreate(['endpoint_hash' => $hash], [
            'user_id' => $user->id,
            'endpoint' => $validated['endpoint'],
            'public_key' => $validated['keys']['p256dh'],
            'auth_token' => $validated['keys']['auth'],
            'content_encoding' => $validated['contentEncoding'] ?? 'aes128gcm',
            'label' => Str::limit(trim((string) ($validated['label'] ?? '')), 120, '') ?: null,
            'session_hash' => $this->sessionHash($request),
        ]);

        // At most so many devices: the oldest go first.
        $keep = PushSubscription::query()->where('user_id', $user->id)->latest('id')->limit((int) config('help.push.devices_per_user'))->pluck('id');
        PushSubscription::query()->where('user_id', $user->id)->whereNotIn('id', $keep)->delete();

        $request->merge(['device' => $hash]);

        return $this->index($request);
    }

    public function destroy(Request $request, int $device)
    {
        PushSubscription::query()->where('user_id', $request->user()->id)->whereKey($device)->delete();

        return $this->index($request);
    }

    // This browser (by its endpoint): turning alerts off here, or signing out.
    public function destroyCurrent(Request $request)
    {
        $endpoint = (string) $request->input('endpoint');
        if ($endpoint !== '') {
            PushSubscription::query()->where('user_id', $request->user()->id)->where('endpoint_hash', PushSubscription::hashEndpoint($endpoint))->delete();
        }

        return response()->json(['removed' => true]);
    }

    // A base64url key of exactly $bytes bytes (and starting with $prefix), or null.
    private function keyOf(mixed $value, int $bytes, string $prefix = ''): ?string
    {
        if (! is_string($value) || ! preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $value)) {
            return null;
        }
        $raw = base64_decode(strtr($value, '-_', '+/'), true);

        return $raw !== false && strlen($raw) === $bytes && str_starts_with($raw, $prefix) ? $raw : null;
    }

    private function sessionHash(Request $request): string
    {
        return hash('sha256', (string) $request->session()->getId());
    }
}
