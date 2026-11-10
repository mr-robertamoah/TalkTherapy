<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // SCRUM-288: "does this minor still have an active guardian" is asked by ward_id on every write
    // a minor makes in a therapy. The (guardian_id, ward_id) unique index can't serve a ward_id lookup.
    public function up(): void
    {
        Schema::table('guardianship', function (Blueprint $table) {
            $table->index('ward_id');
        });
    }

    public function down(): void
    {
        Schema::table('guardianship', function (Blueprint $table) {
            $table->dropIndex(['ward_id']);
        });
    }
};
