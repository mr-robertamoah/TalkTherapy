<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCRUM-427: a new email waits here until its owner confirms it -- until then the current address is the account's
// (password reset included). session_version ends an account's other sessions without its password (a change a
// super administrator asked for, once confirmed -- EndStaleSessions).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pending_email')->nullable()->after('email_verified_at');
            $table->timestamp('pending_email_requested_at')->nullable()->after('pending_email');
            // Who asked: null for the person themselves, else the administrator's user id (no constraint: a log, kept
            // even if that account goes).
            $table->unsignedBigInteger('pending_email_requested_by')->nullable()->after('pending_email_requested_at');
            // When its owner confirmed it. With a verified current address it then waits (security review: an attacker
            // confirms their own address in seconds) -- the scheduler applies it at confirmed + the wait.
            $table->timestamp('pending_email_confirmed_at')->nullable()->after('pending_email_requested_by');
            // The address the account last had verified, and when it stopped being that -- removing the email or
            // unticking "verified" mustn't make a change skip the wait (security review). Protected for 30 days.
            $table->string('last_verified_email')->nullable()->after('pending_email_confirmed_at');
            $table->timestamp('last_verified_email_until')->nullable()->after('last_verified_email');
            $table->unsignedInteger('session_version')->default(0)->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['pending_email', 'pending_email_requested_at', 'pending_email_requested_by', 'pending_email_confirmed_at', 'last_verified_email', 'last_verified_email_until', 'session_version']);
        });
    }
};
