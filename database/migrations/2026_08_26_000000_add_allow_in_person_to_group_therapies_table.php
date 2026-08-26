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
        Schema::table('group_therapies', function (Blueprint $table) {
            // CreateGroupTherapyRequest/UpdateGroupTherapyRequest have always validated and
            // accepted allowInPerson for group therapies, and Create/UpdateGroupTherapyAction
            // have always attempted to write it to allow_in_person -- but this column never
            // existed, so mass-assignment guarding silently dropped it every time (SCRUM-86).
            // Defaulting to false (rather than matching therapies.allow_in_person's no-default,
            // required-at-creation column) since this is an ALTER TABLE on a table that may
            // already have rows, unlike therapies' column which was defined at table-creation
            // time.
            $table->boolean('allow_in_person')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('group_therapies', function (Blueprint $table) {
            $table->dropColumn('allow_in_person');
        });
    }
};
