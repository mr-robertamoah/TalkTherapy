<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // TT-7.11a/SCRUM-470: the star foundation for counsellor recognition (SCRUM-464).
    //  - source: which rule earned the star (App\Enums\StarSourceEnum). NULL is a legacy star, kept on profiles but
    //    never counted by the new rules.
    //  - award_key: names the earning event (e.g. "session_held:42", "post_interaction:{post}:{user}"). Unique per
    //    starred counsellor -- (starred_type, starred_id, award_key) -- so each counsellor of a group session or
    //    discussion gets their own star for the same event, and awarding twice inserts once. Legacy rows have none (a
    //    unique index allows several NULLs on MySQL and SQLite). Key length on MySQL: 1020 + 8 + 600 bytes (utf8mb4),
    //    within InnoDB's 3072.
    //  - stars_period_count_index: serves CountCounsellorStarsAction's ranking query -- equality on starred_type, a
    //    range on created_at (the month), then starred_id and source read from the index itself, so counting and
    //    MIN(created_at) grouped by starred_id never touch table rows. Per-counsellor lookups keep using the existing
    //    (starred_type, starred_id) morph index.
    public function up(): void
    {
        Schema::table('stars', function (Blueprint $table) {
            $table->string('source', 30)->nullable()->after('type');
            $table->string('award_key', 150)->nullable()->after('source');
            $table->unique(['starred_type', 'starred_id', 'award_key'], 'stars_award_key_unique');
            $table->index(['starred_type', 'created_at', 'starred_id', 'source'], 'stars_period_count_index');
        });
    }

    public function down(): void
    {
        Schema::table('stars', function (Blueprint $table) {
            $table->dropIndex('stars_period_count_index');
            $table->dropUnique('stars_award_key_unique');
        });

        Schema::table('stars', function (Blueprint $table) {
            $table->dropColumn(['source', 'award_key']);
        });
    }
};
