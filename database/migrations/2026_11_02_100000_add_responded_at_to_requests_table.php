<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCRUM-362: when a request left PENDING, set once (Request::booted) and never moved again, so the
// document-retention sweeps no longer restart their clock whenever a decided request is touched.
// Decided rows are backfilled from updated_at -- the best record there is of when they were decided.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->timestamp('responded_at')->nullable()->index()->after('status');
        });

        DB::table('requests')
            ->where('status', '!=', 'PENDING')
            ->whereNull('responded_at')
            ->update(['responded_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropIndex(['responded_at']);
            $table->dropColumn('responded_at');
        });
    }
};
