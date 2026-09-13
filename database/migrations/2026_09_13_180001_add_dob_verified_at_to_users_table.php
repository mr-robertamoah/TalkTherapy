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
        // TT-4.11c/SCRUM-304: a persistent marker that `dob` was confirmed via an approved
        // ageVerification request (ApplyVerifiedDobAction), not merely self-reported or set by an
        // ordinary guardian-approved dobChange -- without it, a later ordinary dobChange approval
        // could silently overwrite/undo the "this was verified" guarantee with no record it ever
        // existed. Set whenever a verified dob is applied, cleared whenever an unverified one is.
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('dob_verified_at')->nullable()->after('dob');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('dob_verified_at');
        });
    }
};
