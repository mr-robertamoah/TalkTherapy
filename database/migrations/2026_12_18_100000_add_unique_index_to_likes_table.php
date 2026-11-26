<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCRUM-478: one like per person per thing, enforced by the database (SCRUM-472's check in CreateLikeAction can race).
// Rows from before that check may hold duplicates: the earliest (lowest id) of each is kept, the rest deleted. The
// existing non-unique likeable morph index stays.
return new class extends Migration
{
    public const INDEX = 'likes_user_likeable_unique';

    public function up(): void
    {
        // The derived table is what lets MySQL delete from a table the subquery also reads; SQLite accepts it too.
        DB::statement(
            'DELETE FROM likes WHERE likeable_type IS NOT NULL AND likeable_id IS NOT NULL AND id NOT IN ('
            .'SELECT keep_id FROM ('
            .'SELECT MIN(id) AS keep_id FROM likes WHERE likeable_type IS NOT NULL AND likeable_id IS NOT NULL '
            .'GROUP BY user_id, likeable_type, likeable_id'
            .') AS earliest_likes)'
        );

        Schema::table('likes', function (Blueprint $table) {
            $table->unique(['user_id', 'likeable_type', 'likeable_id'], self::INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('likes', function (Blueprint $table) {
            $table->dropUnique(self::INDEX);
        });
    }
};
