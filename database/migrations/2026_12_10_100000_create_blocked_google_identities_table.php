<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCRUM-456: Google accounts that can't sign up or be connected again, because an administrator closed the account they
// were connected to. Only a keyed hash of Google's id (BlockedGoogleIdentity::hashFor), never the id itself, and in a
// table of its own: a closed account's row is cleared of its Google id at once and anonymised later.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocked_google_identities', function (Blueprint $table) {
            $table->id();
            $table->char('sub_hash', 64)->unique();
            $table->timestamp('blocked_at');
            $table->string('reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_google_identities');
    }
};
