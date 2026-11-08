<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCRUM-39 (TT-5.4d): how long a person keeps their notifications -- null is the platform default
// (config notifications.retention_days). A column, not part of users.settings, so the nightly prune can
// group people by it in SQL.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('notification_retention_days')->nullable()->after('settings');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_retention_days');
        });
    }
};
