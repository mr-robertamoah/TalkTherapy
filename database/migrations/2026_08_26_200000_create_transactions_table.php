<?php

use App\Enums\TransactionStatusEnum;
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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->morphs('for');
            $table->foreignId('user_id')->constrained();
            $table->string('reference')->unique();
            // Minor units (e.g. pesewas/kobo/cents), never a float -- avoids floating-point
            // rounding drift on money.
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);
            $table->enum('status', TransactionStatusEnum::values());
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
