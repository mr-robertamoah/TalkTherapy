<?php

use App\Enums\CounsellorPayoutStatusEnum;
use App\Enums\CounsellorPayoutStatusSourceEnum;
use App\Models\CounsellorPayout;
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
        // Mirrors TransactionStatusHistory's shape exactly, for the payout-execution lifecycle.
        Schema::create('counsellor_payout_status_histories', function (Blueprint $table) {
            $table->id();
            // Explicit short constraint name -- the auto-generated one would exceed MySQL's
            // 64-character identifier limit (same issue hit by counsellor_earning_status_histories,
            // TT-7.6b).
            $table->foreignIdFor(CounsellorPayout::class)
                ->constrained(indexName: 'payout_status_history_payout_id_fk')
                ->cascadeOnDelete();
            $table->enum('status', CounsellorPayoutStatusEnum::values());
            $table->enum('source', CounsellorPayoutStatusSourceEnum::values());
            $table->text('message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('counsellor_payout_status_histories');
    }
};
