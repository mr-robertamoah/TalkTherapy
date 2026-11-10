<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

// SCRUM-399: VAPID keys for web push, made once per environment and kept (as secrets) -- changing them ends
// every device's subscription. Printed, never written anywhere.
class GenerateVapidKeys extends Command
{
    protected $signature = 'push:vapid-keys';

    protected $description = 'Generate a VAPID key pair for urgent help web push (print only).';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->warn('Store these as secrets for this environment and keep them: changing them ends every device subscription.');

        return self::SUCCESS;
    }
}
