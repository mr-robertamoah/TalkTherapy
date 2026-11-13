<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // SCRUM-35b: why a password (checked at sign-in, the only time we have it in plain) doesn't meet today's policy --
    // null when it does -- and when it was last checked against the breached list (null: never, so the next sign-in does).
    // Never the password.
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password_weak_reason', 20)->nullable()->after('password');
            $table->timestamp('password_checked_at')->nullable()->after('password_weak_reason');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['password_weak_reason', 'password_checked_at']);
        });
    }
};
