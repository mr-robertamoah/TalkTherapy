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
        Schema::table('guardianship', function (Blueprint $table) {
            // TT-4.10a/SCRUM-290: captured once, at guardianship-creation time, from the ward's
            // own live isAdult() at that exact moment -- User::isAdult() is computed from the
            // self-editable `dob` field with no upper-boundary protection (SCRUM-283's own
            // security-review finding), so anything that must stay stable regardless of a LATER
            // dob edit needs its own snapshot rather than recomputing live. Nullable in principle
            // only (this action's own single creation path -- RespondToGuardianshipRequestAction
            // -- always has a real ward User in scope, so in practice this is never actually
            // null), matching video_consent_mode's own "nullable = not applicable" precedent for
            // consistency across this codebase's minor-safeguarding columns.
            $table->boolean('ward_was_minor_at_creation')->nullable()->after('ward_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('guardianship', function (Blueprint $table) {
            $table->dropColumn('ward_was_minor_at_creation');
        });
    }
};
