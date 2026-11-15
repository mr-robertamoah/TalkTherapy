<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCRUM-431: the last verified address stays protected however long ago (it was 30 days -- unticking "verified" and
// waiting them out skipped the wait), so when that protection would have ended isn't kept any more.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_verified_email_until');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_verified_email_until')->nullable()->after('last_verified_email');
        });
    }
};
