<?php

use App\Enums\CounsellorVerificationEventEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// SCRUM-31: counsellors verified before verifications could end get the full period from when this
// runs (the user's decision -- not from their old verified_at, so nobody lapses on release day), and
// one `baseline` event saying so. Re-runnable: only rows still without an end date are touched, and
// the baseline's dedupe key is per counsellor. Down leaves the events (append-only) and the dates
// (the previous migration's down drops the columns).
return new class extends Migration
{
    public function up(): void
    {
        $expiresAt = now()->addMonths((int) config('counsellor.verification_validity_months', 12));

        DB::table('counsellors')
            ->whereNotNull('verified_at')
            ->whereNull('verification_expires_at')
            ->orderBy('id')
            ->chunkById(500, function ($counsellors) use ($expiresAt) {
                $ids = $counsellors->pluck('id')->all();

                DB::table('counsellors')->whereIn('id', $ids)->update(['verification_expires_at' => $expiresAt]);
                DB::table('counsellor_verification_events')->insertOrIgnore(array_map(fn ($id) => [
                    'counsellor_id' => $id,
                    'event' => CounsellorVerificationEventEnum::baseline->value,
                    'expires_at' => $expiresAt,
                    'dedupe_key' => "baseline:{$id}",
                    'created_at' => now(),
                ], $ids));
            });
    }

    public function down(): void
    {
        //
    }
};
