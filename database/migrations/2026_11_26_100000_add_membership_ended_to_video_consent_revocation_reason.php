<?php

use App\Enums\VideoConsentRevocationReasonEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // SCRUM-313: a minor's group grants lapse when they leave the group (MEMBERSHIP_ENDED).
    public function up(): void
    {
        Schema::table('video_consents', function (Blueprint $table) {
            $table->enum('revocation_reason', VideoConsentRevocationReasonEnum::values())->nullable()->change();
        });
    }

    public function down(): void
    {
        // Fails while a row has MEMBERSHIP_ENDED -- those grants were lapsed for a member who left.
        Schema::table('video_consents', function (Blueprint $table) {
            $table->enum('revocation_reason', ['GUARDIAN_ACTION', 'GUARDIANSHIP_REMOVED'])->nullable()->change();
        });
    }
};
