<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCRUM-37 (TT-5.2): a questionnaire (PHQ-9, GAD-7) a therapy's counsellor sends its client -- one row per check-in,
// answers in it. Answers, score and level are encrypted; `needs_attention` (a self-harm answer, PHQ-9 item 9) stays
// plain so the counsellor's unacknowledged ones can be found. `open_key` (therapy:measure while requested) lets the
// database itself allow one open check-in per questionnaire per therapy. The rows outlive a counsellor's account
// (clinical record, like session notes).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outcome_check_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('therapy_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('counsellor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('measure', 20);
            $table->unsignedSmallInteger('measure_version');
            $table->string('status', 20);
            $table->timestamp('requested_at');
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->mediumText('answers')->nullable();
            $table->text('total_score')->nullable();
            $table->text('band')->nullable();
            $table->boolean('needs_attention')->default(false);
            $table->timestamp('attention_acknowledged_at')->nullable();
            $table->string('open_key')->nullable()->unique();
            $table->timestamps();

            $table->index(['therapy_id', 'completed_at']);
            $table->index(['counsellor_id', 'needs_attention']);
            $table->index('client_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outcome_check_ins');
    }
};
