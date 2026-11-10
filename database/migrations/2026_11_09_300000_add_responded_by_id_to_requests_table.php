<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCRUM-387 (security review): who answered a request (Request::answeredBy(), set when it is
// accepted or rejected), so the admin queue can say which administrator decided it. Null for a
// request still pending, one closed by the system, or one answered before this existed.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->foreignId('responded_by_id')->nullable()->after('responded_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('responded_by_id');
        });
    }
};
