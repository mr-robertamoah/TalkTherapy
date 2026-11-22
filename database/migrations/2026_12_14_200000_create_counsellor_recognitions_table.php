<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // TT-7.11d1/SCRUM-473: a super administrator's decision about one counsellor for one month -- recognised
    // (approved) or passed over (skipped) -- and the counsellor's featuring consent for an approved one. One row per
    // counsellor per month (unique). `month` is always the 1st of the month (UTC). `consent` is null for a skipped row.
    // `stars_count` and `rank` are the snapshot taken when the decision was made. `platform_fee_waiver_id` is for the
    // per-counsellor waiver SCRUM-475 may attach; nothing sets it yet.
    public function up(): void
    {
        Schema::create('counsellor_recognitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('counsellor_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->string('decision', 20);
            $table->string('consent', 20)->nullable();
            $table->timestamp('consent_changed_at')->nullable();
            $table->boolean('added_manually')->default(false);
            $table->unsignedInteger('stars_count')->nullable();
            $table->unsignedInteger('rank')->nullable();
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('platform_fee_waiver_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['counsellor_id', 'month']);
            $table->index(['month', 'decision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('counsellor_recognitions');
    }
};
