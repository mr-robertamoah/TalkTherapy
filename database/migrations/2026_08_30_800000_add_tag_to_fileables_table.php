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
        Schema::table('fileables', function (Blueprint $table) {
            // Nullable: License/Post/Report/Message keep attaching untagged (NULL) rows, and SQL
            // treats each NULL as distinct in a unique index, so the constraint below only ever
            // enforces at-most-one-row-per-tag for slots that actually use a tag (SCRUM-182/TT-10).
            $table->string('tag')->nullable()->after('fileable_id');
            $table->unique(['fileable_type', 'fileable_id', 'tag']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fileables', function (Blueprint $table) {
            $table->dropUnique(['fileable_type', 'fileable_id', 'tag']);
            $table->dropColumn('tag');
        });
    }
};
