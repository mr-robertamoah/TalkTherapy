<?php

use App\Models\Session;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // TT-3.1e-e/SCRUM-284: exactly-once tracking per (session, reminder window), mirroring
        // AppService::sendCompensationRequestExpiryReminders()'s own reminder_sent_at precedent --
        // day_before/hour_before are independent columns, not two rows, since both reminders can
        // legitimately fire for the same session (they're distinct notifications, not a retry of
        // the same one). One row per Session, created on first reminder sent for it.
        Schema::create('video_consent_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Session::class)->unique()->constrained()->cascadeOnDelete();
            $table->timestamp('day_before_sent_at')->nullable();
            $table->timestamp('hour_before_sent_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_consent_reminders');
    }
};
