<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // TT-7.11b/SCRUM-471: when a discussion was first held -- set once, never cleared, like a session's ended_at. Its
    // star is awarded only that first time, so an administrator reopening and ending it again can't re-date the star.
    public function up(): void
    {
        Schema::table('discussions', function (Blueprint $table) {
            $table->timestamp('held_at')->nullable()->after('status');
        });

        // Discussions already held: their last change is when they were held, near enough -- so an administrator
        // reopening and ending one again doesn't count as its first time.
        DB::table('discussions')->where('status', 'HELD')->update(['held_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('discussions', function (Blueprint $table) {
            $table->dropColumn('held_at');
        });
    }
};
