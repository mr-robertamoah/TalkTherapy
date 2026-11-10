<?php

namespace App\Jobs;

use App\Actions\Help\RingAvailableCounsellorsAction;
use App\Contracts\PushSender;
use App\Enums\HelpRequestStatusEnum;
use App\Models\Counsellor;
use App\Models\HelpRequest;
use App\Models\PushSubscription;
use App\Support\HelpPush;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

// SCRUM-399: pushes an urgent help ring to counsellors' devices, or withdraws it once the request is answered
// or closed. A ring is re-checked when it is sent -- still waiting, and only to counsellors it is ringing now
// (RingAvailableCounsellorsAction::ringingFor) -- so a late job never rings anyone it shouldn't. Who was pushed
// is kept for a while (cache, by request) so the withdrawal reaches every device that was rung. The payload is
// an opaque reference only (reference()); the device shows fixed words (public/sw.js).
class SendHelpPushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const RING = 'ring';

    public const WITHDRAW = 'withdraw';

    public int $tries = 2;

    public int $timeout = 30;

    /** @param list<int> $counsellorIds */
    public function __construct(public string $kind, public int $helpRequestId, public array $counsellorIds) {}

    // What the push services see of a request: an opaque reference (the same for its ring and withdrawal, so the
    // device replaces one with the other), never its id -- sequential ids would show how many people ask, and when.
    public static function reference(int $helpRequestId): string
    {
        return 'h'.substr(rtrim(strtr(base64_encode(hash_hmac('sha256', "help-push:{$helpRequestId}", (string) config('app.key'), true)), '+/', '-_'), '='), 0, 24);
    }

    public static function ledgerKey(int $helpRequestId): string
    {
        return "help-push:{$helpRequestId}";
    }

    public function handle(PushSender $sender): void
    {
        if (! HelpPush::enabled()) {
            return;
        }

        $request = HelpRequest::query()->find($this->helpRequestId);
        $userIds = $this->kind === self::RING ? $this->ringUserIds($request) : $this->withdrawUserIds($request);
        if ($userIds === []) {
            return;
        }

        $subscriptions = PushSubscription::query()->whereIn('user_id', $userIds)->get();
        if ($subscriptions->isEmpty()) {
            return;
        }

        $reference = self::reference($this->helpRequestId);
        $payload = json_encode(['type' => $this->kind, 'tag' => $reference]);
        $results = $sender->send($subscriptions, $payload, [
            'TTL' => max(0, (int) config('help.push.ttl')),
            'urgency' => 'high',
            // A withdrawal replaces a ring the device hasn't fetched yet.
            'topic' => $reference,
        ]);

        $gone = array_keys(array_filter($results, fn (string $status) => $status === PushSender::GONE));
        if ($gone) {
            PushSubscription::query()->whereKey($gone)->delete();
        }
        $sent = array_keys(array_filter($results, fn (string $status) => $status === PushSender::SENT));
        if ($sent) {
            PushSubscription::query()->whereKey($sent)->update(['last_used_at' => now()]);
        }
        $failed = count(array_filter($results, fn (string $status) => $status === PushSender::FAILED));
        if ($failed) {
            Log::warning('Urgent help push not delivered to some devices.', ['helpRequest' => $this->helpRequestId, 'kind' => $this->kind, 'failed' => $failed]);
        }
    }

    /** @return list<int> */
    private function ringUserIds(?HelpRequest $request): array
    {
        if (! $request || $request->status !== HelpRequestStatusEnum::waiting) {
            return [];
        }

        $counsellorIds = array_values(array_intersect($this->counsellorIds, RingAvailableCounsellorsAction::ringingFor($request)));
        $userIds = Counsellor::query()->whereKey($counsellorIds)->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        $key = self::ledgerKey($this->helpRequestId);
        // Locked: two ring jobs for one request (a re-ring) mustn't drop each other's entries.
        Cache::lock($key.':lock', 10)->block(5, fn () => Cache::put($key, array_values(array_unique([...Cache::get($key, []), ...$userIds])), now()->addMinutes((int) config('help.expire_after_minutes') + 10)));

        return $userIds;
    }

    /** @return list<int> */
    private function withdrawUserIds(?HelpRequest $request): array
    {
        $key = self::ledgerKey($this->helpRequestId);
        $named = Counsellor::query()->whereKey($this->counsellorIds)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $userIds = array_values(array_unique([...$named, ...Cache::get($key, [])]));

        // Still waiting (an administrator rang someone else instead): only those named are withdrawn -- and not
        // one it is still ringing (on call), whose device should keep showing it.
        if ($request && $request->status === HelpRequestStatusEnum::waiting) {
            $stillRung = Counsellor::query()->whereKey(RingAvailableCounsellorsAction::ringingFor($request))->pluck('user_id')->map(fn ($id) => (int) $id)->all();

            return array_values(array_diff($named, $stillRung));
        }

        Cache::forget($key);

        return $userIds;
    }
}
