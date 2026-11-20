<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // TT-7.10a/SCRUM-467: a period with no platform fee, [starts_at, ends_at) -- ends_at null is open-ended. A row
    // with no counsellor covers the whole platform; per-counsellor rows are for SCRUM-464. Periods don't overlap
    // (validated where they're created). Stored fees are never rewritten when a period changes: an earning or
    // invoice line records the waiver its fee was decided under instead.
    //
    // counsellor_id cascades rather than nulling on delete: a null counsellor means "everyone", so nulling it would
    // turn one counsellor's waiver into a platform-wide one.
    public function up(): void
    {
        Schema::create('platform_fee_waivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('counsellor_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_fee_waivers');
    }
};
