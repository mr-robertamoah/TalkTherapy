<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // TT-7.11a/SCRUM-470: the star foundation for counsellor recognition (SCRUM-464).
    //  - source: which rule earned the star (App\Enums\StarSourceEnum). NULL is a legacy star, kept on profiles but
    //    never counted by the new rules.
    //  - award_key: one star per earning event (e.g. "session_held:42"); unique, so awarding twice inserts once.
    //    Legacy rows have none (a unique index allows several NULLs on MySQL and SQLite).
    //  - stars_period_count_index: serves CountCounsellorStarsAction's ranking query -- equality on starred_type, a
    //    range on created_at (the month), then starred_id and source read from the index itself, so counting and
    //    MIN(created_at) grouped by starred_id never touch table rows. Per-counsellor lookups keep using the existing
    //    (starred_type, starred_id) morph index.
    public function up(): void
    {
        Schema::table('stars', function (Blueprint $table) {
            $table->string('source', 30)->nullable()->after('type');
            $table->string('award_key', 150)->nullable()->unique()->after('source');
            $table->index(['starred_type', 'created_at', 'starred_id', 'source'], 'stars_period_count_index');
        });
    }

    public function down(): void
    {
        Schema::table('stars', function (Blueprint $table) {
            $table->dropIndex('stars_period_count_index');
            $table->dropUnique(['award_key']);
        });

        Schema::table('stars', function (Blueprint $table) {
            $table->dropColumn(['source', 'award_key']);
        });
    }
};
