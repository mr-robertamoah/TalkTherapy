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
        // SCRUM-23/TT-2.4: null (the default) means unlimited -- an existing discussion with no
        // cap set keeps behaving exactly as before. Set only by the discussion's own creator
        // (addedby), gated through the existing EnsureCanUpdateDiscussionAction, not a new
        // isAdmin()-only check -- see decision-log.md.
        Schema::table('discussions', function (Blueprint $table) {
            $table->unsignedInteger('max_counsellors')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('discussions', function (Blueprint $table) {
            $table->dropColumn('max_counsellors');
        });
    }
};
