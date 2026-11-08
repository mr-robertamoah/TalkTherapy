<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCRUM-33 (TT-4.4b): the admin side of a help request -- when administrators were alerted that it was
// waiting (first and again), the counsellor an administrator rang directly (who may then accept it
// even if not on call), and an administrator closing it with a note.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('help_requests', function (Blueprint $table) {
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('escalated_again_at')->nullable();
            $table->unsignedBigInteger('pulled_counsellor_id')->nullable();
            $table->unsignedBigInteger('pulled_by_id')->nullable();
            $table->timestamp('pulled_at')->nullable();
            $table->unsignedBigInteger('closed_by_id')->nullable();
            $table->string('closed_note', 300)->nullable();
            // The queue's "the last day".
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('help_requests', function (Blueprint $table) {
            $table->dropIndex(['updated_at']);
            $table->dropColumn(['escalated_at', 'escalated_again_at', 'pulled_counsellor_id', 'pulled_by_id', 'pulled_at', 'closed_by_id', 'closed_note']);
        });
    }
};
