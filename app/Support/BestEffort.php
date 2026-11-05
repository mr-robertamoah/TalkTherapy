<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

// SCRUM-382: one step of work that reaches outside the database after a change has been saved (a
// broadcast, a notification, a provider call). Its failure is logged and swallowed, so it can't
// undo the change or stop the steps after it. $what names the step in the log; $context is ids only.
final class BestEffort
{
    public static function attempt(callable $step, string $what, array $context = []): mixed
    {
        try {
            return $step();
        } catch (Throwable $th) {
            Log::warning('A best-effort step failed.', ['step' => $what, ...$context, 'error' => $th->getMessage()]);

            return null;
        }
    }
}
