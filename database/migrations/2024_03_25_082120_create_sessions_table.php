<?php

use App\Enums\SessionStatusEnum;
use App\Enums\SessionTypeEnum;
use App\Enums\TherapyPaymentTypeEnum;
use App\Models\Therapy;
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
        Schema::create('sessions', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('addedby');
            $table->nullableMorphs('updatedby');
            $table->foreignIdFor(Therapy::class);
            $table->string('name');
            $table->string('landmark')->nullable();
            $table->text('about');
            $table->decimal('longitude', 11, 8)->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->enum('type', SessionTypeEnum::values());
            $table->enum('payment_type', TherapyPaymentTypeEnum::values());
            $table->enum('status', SessionStatusEnum::values());
            $table->timestamp('start_time');
            $table->timestamp('end_time');
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
