<?php

use App\Models\GroupTherapy;
use App\Models\Therapy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('therapy_topics', function (Blueprint $table) {
            $table->nullableMorphs('topicable');
        });

        DB::statement("UPDATE therapy_topics SET topicable_id = therapy_id, topicable_type = 'App\\\Models\\\Therapy' WHERE therapy_id IS NOT NULL");
    
        if ($this->foreignKeyExists('therapy_topics', 'therapy_topics_therapy_id_foreign'))
            Schema::table('therapy_topics', function (Blueprint $table) {
                $table->dropForeign(['therapy_id']);
            });

        Schema::table('therapy_topics', function (Blueprint $table) {
            $table->dropColumn(['therapy_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('therapy_topics', function (Blueprint $table) {
            $table->foreignIdFor(Therapy::class);
        });

        DB::statement("UPDATE therapy_topics SET therapy_id = topicable_id WHERE topicable_type = 'App\\\Models\\\Therapy'");

        Schema::table('therapy_topics', function (Blueprint $table) {
            $table->dropColumn(['topicable_id', 'topicable_type']);
        });
    }

    private function foreignKeyExists(string $table, $foreignKeyName): bool
    {
        return DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_NAME = ? AND CONSTRAINT_NAME = ?
        ", [$table, $foreignKeyName]) ? true : false;
    }
};
