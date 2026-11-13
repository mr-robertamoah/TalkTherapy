<?php

use App\Enums\SpeakingPermissionRevocationReasonEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // SCRUM-329: an under-18's floor ends when their guardians' consent no longer lets them speak (GUARDIAN_CONSENT).
    public function up(): void
    {
        Schema::table('video_session_speaking_grants', function (Blueprint $table) {
            $table->enum('revocation_reason', SpeakingPermissionRevocationReasonEnum::values())->nullable()->change();
        });
    }

    public function down(): void
    {
        // Fails while a row has GUARDIAN_CONSENT.
        Schema::table('video_session_speaking_grants', function (Blueprint $table) {
            $table->enum('revocation_reason', ['MANUAL', 'IDLE_AUTO_EXPIRY', 'PARTICIPANT_REMOVED'])->nullable()->change();
        });
    }
};
