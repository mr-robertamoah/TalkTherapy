<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCRUM-31: a platform verification now ends (verification_expires_at) and can be revoked by an
// administrator (verification_revoked_at). verified_at keeps meaning "first verified" and is never
// cleared. Counsellor::verificationState() reads all three -- columns only, so a list of counsellors
// shows its badges without a query each.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('counsellors', function (Blueprint $table) {
            $table->timestamp('verification_expires_at')->nullable()->after('verified_at');
            $table->timestamp('verification_revoked_at')->nullable()->after('verification_expires_at');

            // The daily reminder and lapse sweeps, and the admins' renewals list.
            $table->index(['verification_revoked_at', 'verification_expires_at'], 'counsellors_verification_state_index');
        });
    }

    public function down(): void
    {
        Schema::table('counsellors', function (Blueprint $table) {
            $table->dropIndex('counsellors_verification_state_index');
            $table->dropColumn(['verification_expires_at', 'verification_revoked_at']);
        });
    }
};
