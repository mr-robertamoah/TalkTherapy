<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCRUM-456: a Google sign-up's "is this email free?" check (PendingGoogleSignUp::emailIsFree) also looks addresses up
// in pending_email and last_verified_email -- without these, a table scan per sign-up.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->index('pending_email');
            $table->index('last_verified_email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['pending_email']);
            $table->dropIndex(['last_verified_email']);
        });
    }
};
