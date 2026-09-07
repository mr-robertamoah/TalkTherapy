<?php

use App\Enums\RefundStatusEnum;
use App\Enums\RefundStatusSourceEnum;
use App\Models\Refund;
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
        // Mirrors TransactionStatusHistory's shape exactly, for the refund-execution lifecycle.
        Schema::create('refund_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Refund::class)->constrained()->cascadeOnDelete();
            $table->enum('status', RefundStatusEnum::values());
            $table->enum('source', RefundStatusSourceEnum::values());
            $table->text('message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('refund_status_histories');
    }
};
