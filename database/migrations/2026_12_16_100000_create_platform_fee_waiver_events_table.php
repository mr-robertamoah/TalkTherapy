<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // TT-7.11e/SCRUM-475: every change to a commission-free period -- platform-wide or one counsellor's -- and who made
    // it (App\Enums\PlatformFeeWaiverEventEnum: created, ended, deleted). Written only by PlatformFeeWaiverService.
    //
    // platform_fee_waiver_id deliberately has no foreign key, so a deleted period's history stays (its data keeps the
    // dates and the reason). counsellor_id has none either, for the same reason: null is platform-wide.
    public function up(): void
    {
        Schema::create('platform_fee_waiver_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('platform_fee_waiver_id')->index();
            $table->unsignedBigInteger('counsellor_id')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 30);
            $table->json('data')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_fee_waiver_events');
    }
};
