<?php

namespace App\Contracts;

use App\Models\PushSubscription;

// SCRUM-399: sends one web push payload to devices. Bound to the minishlink implementation; tests bind a fake.
interface PushSender
{
    public const SENT = 'sent';

    public const GONE = 'gone';   // the push service says the subscription no longer exists (404/410)

    public const FAILED = 'failed';

    /**
     * @param  iterable<PushSubscription>  $subscriptions
     * @return array<int, string> subscription id => SENT, GONE or FAILED
     */
    public function send(iterable $subscriptions, string $payload, array $options): array;
}
