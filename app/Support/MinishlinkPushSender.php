<?php

namespace App\Support;

use App\Contracts\PushSender;
use App\Models\PushSubscription;
use GuzzleHttp\Client;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

// SCRUM-399: web push through minishlink/web-push, with this environment's VAPID keys (config help.push).
// Endpoints are never logged: a failure is reported by subscription id only. The HTTP client is passed in so a
// push service that hangs can't hold the queue worker (WebPush's options array takes push options, not HTTP ones).
final class MinishlinkPushSender implements PushSender
{
    public function send(iterable $subscriptions, string $payload, array $options): array
    {
        $results = [];
        foreach ($subscriptions as $subscription) {
            // Saved before the allowlist, or the list changed: never posted to, and removed.
            if (! PushSubscription::endpointAllowed((string) $subscription->endpoint)) {
                $results[$subscription->id] = self::GONE;

                continue;
            }

            $webPush ??= new WebPush(['VAPID' => [
                'subject' => (string) config('help.push.vapid_subject'),
                'publicKey' => (string) config('help.push.vapid_public_key'),
                'privateKey' => (string) config('help.push.vapid_private_key'),
            ]], [], new Client(['connect_timeout' => 3, 'timeout' => 5, 'allow_redirects' => false]));

            // One at a time: a device whose keys can't be used fails (and is removed) on its own, never the batch.
            try {
                $report = $webPush->sendOneNotification(Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'publicKey' => $subscription->public_key,
                    'authToken' => $subscription->auth_token,
                    'contentEncoding' => $subscription->content_encoding,
                ]), $payload, $options);
            } catch (\ErrorException|\InvalidArgumentException) {
                $results[$subscription->id] = self::GONE;

                continue;
            } catch (\Throwable) {
                $results[$subscription->id] = self::FAILED;

                continue;
            }

            $results[$subscription->id] = match (true) {
                $report->isSuccess() => self::SENT,
                $report->isSubscriptionExpired() => self::GONE,
                default => self::FAILED,
            };
        }

        return $results;
    }
}
