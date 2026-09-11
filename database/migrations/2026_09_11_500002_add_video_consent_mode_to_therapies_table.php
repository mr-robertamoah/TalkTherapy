<?php

use App\Enums\VideoConsentModeEnum;
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
        Schema::table('therapies', function (Blueprint $table) {
            // TT-3.1e-a/SCRUM-280: nullable -- null means "not set", the common case for an
            // adult-only therapy. A dedicated column (not folded into the existing payment_data
            // JSON blob) so EnsureVideoIsAvailableForSessionAction's join-hot-path check
            // (TT-3.1e-d) can query/index it directly -- architect decision, 2026-09-11, see
            // documentation/decision-log.md.
            $table->enum('video_consent_mode', VideoConsentModeEnum::values())->nullable()->after('payment_data');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('therapies', function (Blueprint $table) {
            $table->dropColumn('video_consent_mode');
        });
    }
};
