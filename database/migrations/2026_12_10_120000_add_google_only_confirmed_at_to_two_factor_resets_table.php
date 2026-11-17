<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCRUM-456 (security review M2): when the person asking agreed that the reset may leave Google as the account's only
// way in (user:two-factor-reset). Without it, a reset that would do that is cancelled when due.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('two_factor_resets', function (Blueprint $table) {
            $table->timestamp('google_only_confirmed_at')->nullable()->after('mail_required');
        });
    }

    public function down(): void
    {
        Schema::table('two_factor_resets', function (Blueprint $table) {
            $table->dropColumn('google_only_confirmed_at');
        });
    }
};
