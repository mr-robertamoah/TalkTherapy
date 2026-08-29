<?php

use App\Enums\SessionTypeEnum;
use App\Enums\TherapyPerPaymentEnum;
use App\Enums\TherapyTypeEnum;
use App\Models\Counsellor;
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
        // A counsellor is in exactly one of two modes (enforced in
        // EnsureCounsellorPricingDataIsValidAction, not at the schema level): a single flat row
        // (therapy_type/session_type/per all null), or N override rows, each fully specifying all
        // three scope dimensions -- never a partial row, never both modes at once. Every save is a
        // full delete-and-reinsert (SetCounsellorPricingAction), not incremental upsert, so there's
        // no history/versioning here -- unlike organization_counsellor_compensations, there's no
        // negotiation or accountability trail to reproduce for a unilateral, informational number.
        Schema::create('counsellor_pricings', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Counsellor::class);
            $table->enum('therapy_type', TherapyTypeEnum::values())->nullable();
            $table->enum('session_type', SessionTypeEnum::values())->nullable();
            $table->enum('per', TherapyPerPaymentEnum::values())->nullable();
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('counsellor_pricings');
    }
};
