<?php

use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapySessionTypeEnum;
use App\Enums\TherapyStatusEnum;
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
        Schema::create('group_therapies', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('addedby');
            $table->enum('session_type', TherapySessionTypeEnum::values());
            $table->enum('payment_type', TherapyPaymentTypeEnum::values());
            $table->enum('status', TherapyStatusEnum::values());
            $table->text('about')->nullable();
            $table->string('name');
            $table->boolean('public');
            $table->boolean('anonymous');
            $table->boolean('allow_anyone');
            $table->integer('max_sessions')->default(10);
            $table->integer('max_users')->default(10);
            $table->json('payment_data')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('group_therapies');
    }
};
