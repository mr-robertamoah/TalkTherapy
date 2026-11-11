<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCRUM-415: when a single-use link was used (Link::markUsed()). Tells a used link from one its creator
// switched off, so a used one can never be switched back on (ChangeLinkStatusAction). Links used before
// this column existed have none.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->timestamp('used_at')->nullable()->after('state');
        });
    }

    public function down(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->dropColumn('used_at');
        });
    }
};
