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
        // TT-3.2f-b/SCRUM-319: closes a real detection gap found while scoping SCRUM-314 -- only a
        // group's own creator/addedby has a stable minor snapshot (client_was_minor_at_creation);
        // an ordinary member has never had one (RespondToGroupTherapyMembershipRequestAction's own
        // comment on its AlertGuardianAction call already flagged this exact absence). Nullable so
        // every pre-existing membership row (predating this column) falls back to a live isAdult()
        // re-check via GroupTherapy::memberIsMinor()'s own null-fallback, mirroring
        // TherapyTrait::clientIsMinor()'s identical fallback pattern -- never defaulted to a
        // specific true/false here, which would misrepresent an unknown past state.
        Schema::table('group_therapy_user', function (Blueprint $table) {
            $table->boolean('was_minor_at_join')->nullable()->after('anonymous');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('group_therapy_user', function (Blueprint $table) {
            $table->dropColumn('was_minor_at_join');
        });
    }
};
