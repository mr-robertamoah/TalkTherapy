<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

// A live update that is best effort: sent once the database work is committed, and a broadcasting
// failure (Reverb down) is reported, never thrown -- the action it follows has already succeeded.
// Extracted from HelpBroadcast (SCRUM-33 devops review) for SCRUM-32's safety flags.
final class BestEffortBroadcast
{
    public static function send(object $event): void
    {
        DB::afterCommit(fn () => rescue(fn () => event($event), report: true));
    }
}
