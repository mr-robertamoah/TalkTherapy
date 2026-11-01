<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCRUM-359: a suspension of a person (their whole account, or only their counsellor role), always
// applied from the report that led to it. Rows are never deleted: lifting, the end of the period
// and an appeal only fill in their own columns. Whether one is in force is worked out from the
// timestamps on every request (Suspension::scopeActive), so a missed scheduler run can never make
// a suspension last longer. One appeal per suspension, so its columns live here.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suspensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('report_id')->constrained()->restrictOnDelete();
            $table->string('scope', 20);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable(); // null: until lifted
            $table->text('public_reason');
            $table->text('internal_note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('lifted_at')->nullable();
            $table->foreignId('lifted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('lift_reason')->nullable();
            $table->timestamp('end_processed_at')->nullable(); // the expiry sweep told the person
            $table->text('appeal_text')->nullable();
            $table->timestamp('appealed_at')->nullable();
            $table->string('appeal_decision', 20)->nullable();
            $table->text('appeal_note')->nullable();
            $table->foreignId('appeal_decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('appeal_decided_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'scope', 'lifted_at', 'ends_at']);
            $table->index(['ends_at', 'lifted_at', 'end_processed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suspensions');
    }
};
