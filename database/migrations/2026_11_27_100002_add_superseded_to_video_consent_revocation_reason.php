<?php

use App\Enums\VideoConsentRevocationReasonEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // SCRUM-329: a grant closed because a new one replaced it (SUPERSEDED), told apart from a withdrawal.
    public function up(): void
    {
        Schema::table('video_consents', function (Blueprint $table) {
            $table->enum('revocation_reason', VideoConsentRevocationReasonEnum::values())->nullable()->change();
        });
    }

    public function down(): void
    {
        // Fails while a row has SUPERSEDED.
        Schema::table('video_consents', function (Blueprint $table) {
            $table->enum('revocation_reason', ['GUARDIAN_ACTION', 'GUARDIANSHIP_REMOVED', 'MEMBERSHIP_ENDED'])->nullable()->change();
        });
    }
};
