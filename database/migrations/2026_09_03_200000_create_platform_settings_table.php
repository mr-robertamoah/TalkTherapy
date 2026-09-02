<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Generic key/value settings mechanism (TT-7.6b/SCRUM-226): the first two keys are
        // `SettingsEnum::platformFeePercentage`/`minimumPayoutAmount`, but the shape is
        // deliberately not payout-specific -- the user explicitly asked for the platform fee to
        // reuse the SAME mechanism as the minimum-payout-threshold rather than a second one, and
        // a dedicated `payout_settings` table would fail that the moment a non-payout setting
        // shows up, which is very likely on this platform. No typed-value casting is built yet --
        // two scalar/JSON-string values don't need it; add that if a third, differently-typed
        // setting shows up (SettingsService::get() is the one place that would change).
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            // Accountability trail, mirrors organization_counsellor_compensations' set_by_id --
            // only ever a super admin (EnsureIsSuperAdminAction), so a plain FK, not a morph.
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
