<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCRUM-40 (TT-5.5): which kinds of notification the person switched off, by email or in the app --
// {"sessions": {"mail": false}}. Only what they changed: null (everyone, to start with) means everything is sent.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_channels')->nullable()->after('notification_retention_days');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_channels');
        });
    }
};
