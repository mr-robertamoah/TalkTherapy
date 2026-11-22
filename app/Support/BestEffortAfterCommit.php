<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

// SCRUM-471: a side effect of a change that must never undo or block it (a star awarded or taken back): run once the
// database work is committed -- outside the caller's transaction and its row locks -- with a failure reported, never
// thrown. Runs straight away when no transaction is open. As BestEffortBroadcast, for any step.
final class BestEffortAfterCommit
{
    public static function run(callable $step): void
    {
        DB::afterCommit(fn () => rescue($step, report: true));
    }
}
