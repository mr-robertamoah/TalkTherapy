<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCRUM-433: when the remembered address (last_verified_email) was remembered. "This wasn't me" puts it back as the
// account's verified email only while that is recent (auth.email_stop_restore_days); rows from before this have no
// date and are never put back -- whoever holds an old mailbox now may not be the owner. Super administrators see it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_verified_email_at')->nullable()->after('last_verified_email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_verified_email_at');
        });
    }
};
