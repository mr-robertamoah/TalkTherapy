<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCRUM-381: a super administrator's time-limited safeguarding exception for a minor whose therapy is
// paused because they have no active guardian (SCRUM-288). While one is in force the minor is not
// paused (User::isPausedForMissingGuardian()), across all their therapies and groups. Rows are never
// deleted: ending one early or renewing it only fills in its own columns (a renewal is a new row
// pointing back at the one it replaced). Whether one is in force is worked out from the timestamps on
// every request (SafeguardingException::scopeActive), so a missed scheduler run can't make one last
// longer; the sweeps only record the end (end_processed_at) and send the heads-up (expiry_reminded_at).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safeguarding_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ward_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('request_id')->nullable()->constrained('requests')->nullOnDelete();
            $table->text('reason')->nullable(); // null only once the minor's closed account is anonymised
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('ended_at')->nullable(); // ended early or renewed; null when it simply ran out
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('end_kind', 20)->nullable(); // revoked | renewed
            $table->text('end_reason')->nullable();
            $table->timestamp('end_processed_at')->nullable(); // the end was handled (pause scheduled, if any)
            $table->timestamp('expiry_reminded_at')->nullable(); // the 3-day heads-up went out
            $table->foreignId('previous_id')->nullable()->constrained('safeguarding_exceptions')->nullOnDelete();
            $table->timestamps();

            $table->index(['ward_id', 'ended_at', 'ends_at']);
            $table->index(['ended_at', 'end_processed_at', 'ends_at']); // the expiry sweep: two equalities (null), then the range
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safeguarding_exceptions');
    }
};
