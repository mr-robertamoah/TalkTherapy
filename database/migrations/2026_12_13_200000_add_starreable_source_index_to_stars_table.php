<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // TT-7.11b/SCRUM-471: revoking a rule's stars for a thing (RevokeCounsellorStarsAction::forStarreable(s) -- a
    // session gone bad or refunded, a discussion abandoned) deletes by source and starreable. Without an index the
    // DELETE scans and locks far more of `stars` than the rows it removes.
    public function up(): void
    {
        Schema::table('stars', function (Blueprint $table) {
            $table->index(['source', 'starreable_type', 'starreable_id'], 'stars_source_starreable_index');
        });
    }

    public function down(): void
    {
        Schema::table('stars', function (Blueprint $table) {
            $table->dropIndex('stars_source_starreable_index');
        });
    }
};
