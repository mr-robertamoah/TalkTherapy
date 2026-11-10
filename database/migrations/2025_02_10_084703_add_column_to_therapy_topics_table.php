<?php

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

        if ($this->foreignKeyExists('therapy_topics', 'therapy_topics_therapy_id_foreign')) {
            Schema::table('therapy_topics', function (Blueprint $table) {
                $table->dropForeign(['therapy_id']);
            });
        }

        Schema::table('therapy_topics', function (Blueprint $table) {
            $table->dropColumn(['therapy_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Nullable, and the morph columns dropped with their index: SQLite can't add a NOT NULL column without a
        // default, nor drop an indexed column (SCRUM-413). No foreign key to restore: the table's original
        // therapy_id (2024_03_24 create migration) was foreignIdFor() without constrained(). Topics of a group
        // therapy, which the old schema had no place for, are left with a null therapy_id (SCRUM-414).
        Schema::table('therapy_topics', function (Blueprint $table) {
            $table->foreignIdFor(Therapy::class)->nullable();
        });

        DB::statement("UPDATE therapy_topics SET therapy_id = topicable_id WHERE topicable_type = 'App\\\Models\\\Therapy'");

        Schema::table('therapy_topics', function (Blueprint $table) {
            $table->dropMorphs('topicable');
        });
    }

    private function foreignKeyExists(string $table, $foreignKeyName): bool
    {
        $connection = DB::connection()->getDriverName();

        if ($connection === 'sqlite') {
            // SQLite: Check via PRAGMA foreign_key_list
            $result = DB::select("PRAGMA foreign_key_list($table)");
            foreach ($result as $fk) {
                if (($fk->table ?? $fk->to ?? '') === 'therapies' || str_contains($fk->table ?? '', 'therapy')) {
                    return true;
                }
            }

            return false;
        }

        // MySQL/PostgreSQL: information_schema, in this connection's own database/schema only -- on a server
        // shared by several databases, a constraint of the same name elsewhere must not count (SCRUM-414).
        $schema = $connection === 'pgsql' ? 'current_schema()' : 'DATABASE()';

        return DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = {$schema} AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?
        ", [$table, $foreignKeyName]) ? true : false;
    }
};
