<?php

use App\Enums\CounsellorEarningStatusEnum;
use App\Enums\CounsellorEarningStatusSourceEnum;
use App\Models\CounsellorEarning;
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
        // Own table, not a retrofit of TransactionStatusHistory -- different subject (an earning,
        // not a payment-gateway transaction), same "every state change is its own row" shape.
        Schema::create('counsellor_earning_status_histories', function (Blueprint $table) {
            $table->id();
            // Explicit short constraint name -- the auto-generated one
            // ("counsellor_earning_status_histories_counsellor_earning_id_foreign") exceeds
            // MySQL's 64-character identifier limit.
            $table->foreignIdFor(CounsellorEarning::class)
                ->constrained(indexName: 'earning_status_history_earning_id_fk')
                ->cascadeOnDelete();
            $table->enum('status', CounsellorEarningStatusEnum::values());
            $table->enum('source', CounsellorEarningStatusSourceEnum::values());
            $table->text('message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('counsellor_earning_status_histories');
    }
};
