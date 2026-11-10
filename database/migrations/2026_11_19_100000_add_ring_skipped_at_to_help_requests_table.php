<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCRUM-395 (the user's decision): when a request's automatic ring was skipped by the platform-wide cap,
// administrators see it marked in their queue (and are alerted at once). Cleared when they ring it again.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('help_requests', function (Blueprint $table) {
            $table->timestamp('ring_skipped_at')->nullable()->after('escalated_again_at');
        });
    }

    public function down(): void
    {
        Schema::table('help_requests', function (Blueprint $table) {
            $table->dropColumn('ring_skipped_at');
        });
    }
};
