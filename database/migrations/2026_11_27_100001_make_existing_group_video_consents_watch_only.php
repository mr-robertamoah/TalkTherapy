<?php

use App\Enums\VideoConsentLevelEnum;
use App\Models\GroupTherapy;
use App\Models\Session;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // SCRUM-329 (devops review): group consents given before guardians could choose a level (SCRUM-313 PR 2) were
    // stored as SPEAK, while the panel told the guardian their child would watch and listen only. Now that SPEAK lets a
    // minor speak, they're made what the guardian agreed to: WATCH. One-to-one grants are untouched (SPEAK has always
    // been what they mean).
    public function up(): void
    {
        DB::table('video_consents')
            ->where('level', VideoConsentLevelEnum::speak->value)
            ->where(fn ($query) => $query
                ->where('consentable_type', GroupTherapy::class)
                ->orWhere(fn ($q) => $q->where('consentable_type', Session::class)
                    ->whereIn('consentable_id', DB::table('sessions')->where('for_type', GroupTherapy::class)->select('id'))))
            ->update(['level' => VideoConsentLevelEnum::watch->value]);
    }

    // Not reversed: which of them were SPEAK by a guardian's choice can't be told apart afterwards.
    public function down(): void {}
};
