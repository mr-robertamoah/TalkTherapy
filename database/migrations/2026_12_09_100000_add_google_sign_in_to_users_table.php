<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    // SCRUM-452 (Google sign-in, SCRUM-112): the Google account an account is linked to (its stable `sub`, unique --
    // one Google account, one TalkTherapy account) and when it was linked. The password becomes optional: an account
    // made with Google has none until its owner sets one, and every password check refuses an account without one.
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_id')->nullable()->unique()->after('password');
            $table->timestamp('google_linked_at')->nullable()->after('google_id');
            $table->string('password')->nullable()->change();
        });
    }

    // Going back to NOT NULL fails while any account has no password, so those first get a random one nobody knows (they
    // can still reset it by email; an account without an email needs support). One shared random hash in one update
    // (review): paging through rows while updating them out of the filter would skip some, and hashing each is slow.
    // Run it on the current code, before deploying the old one -- the old code doesn't have this file to roll back.
    public function down(): void
    {
        DB::table('users')->whereNull('password')->update(['password' => Hash::make(Str::random(64))]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['google_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['google_id', 'google_linked_at']);
            $table->string('password')->nullable(false)->change();
        });
    }
};
