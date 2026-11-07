<?php

use App\Enums\CounsellorVerificationEventEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// SCRUM-31: counsellors verified before verifications could end get the full period from when this
// runs (the user's decision -- not from their old verified_at, so nobody lapses on release day), plus
// a spread of up to two months (SCRUM-389), and
// one `baseline` event saying so. Re-runnable: only rows still without an end date are touched, and
// the baseline's dedupe key is per counsellor. Down leaves the events (append-only) and the dates
// (the previous migration's down drops the columns).
return new class extends Migration
{
    // SCRUM-389 (the user's decision): spread over about two months so renewals don't all land in one
    // week -- each counsellor gets 12 months plus 0-60 days, fixed by their id (the same on every run).
    // Nobody gets less than the full period. Kept inline so this migration never changes with app code.
    private const SPREAD_DAYS = 61;

    public function up(): void
    {
        $base = now()->addMonths((int) config('counsellor.verification_validity_months', 12));

        DB::table('counsellors')
            ->whereNotNull('verified_at')
            ->whereNull('verification_expires_at')
            ->orderBy('id')
            ->chunkById(500, function ($counsellors) use ($base) {
                $events = [];

                foreach ($counsellors->pluck('id')->groupBy(fn ($id) => $id % self::SPREAD_DAYS) as $offset => $ids) {
                    $expiresAt = $base->copy()->addDays($offset);
                    DB::table('counsellors')->whereIn('id', $ids->all())->update(['verification_expires_at' => $expiresAt]);

                    foreach ($ids as $id) {
                        $events[] = [
                            'counsellor_id' => $id,
                            'event' => CounsellorVerificationEventEnum::baseline->value,
                            'expires_at' => $expiresAt,
                            'dedupe_key' => "baseline:{$id}",
                            'created_at' => now(),
                        ];
                    }
                }

                DB::table('counsellor_verification_events')->insertOrIgnore($events);
            });
    }

    public function down(): void
    {
        //
    }
};
