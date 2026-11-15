<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCRUM-445 (SCRUM-115): who came to an in-person session -- one row per client or member, marked PRESENT or ABSENT by
// a counsellor of the therapy or group. No free text. The marking counsellor is kept as a nullable link: counsellor
// rows are force-deleted on a schedule (as for session notes), and the record outlives them.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20);
            $table->foreignId('marked_by_counsellor_id')->nullable()->constrained('counsellors')->nullOnDelete();
            $table->timestamp('marked_at');
            $table->timestamps();

            // Also the session_id foreign key's index; user_id's foreign key has its own.
            $table->unique(['session_id', 'user_id']);
        });
    }

    // The indexes go with the table (as create_session_notes_table: dropping them first fails on MySQL -- the foreign
    // keys need them).
    public function down(): void
    {
        Schema::dropIfExists('session_attendances');
    }
};
