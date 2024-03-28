<?php

use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapySessionTypeEnum;
use App\Enums\TherapyStatusEnum;
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
        Schema::create('therapies', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('addedby');
            $table->foreignIdFor(Counsellor::class)->nullable();
            $table->enum('session_type', TherapySessionTypeEnum::values());
            $table->enum('payment_type', TherapyPaymentTypeEnum::values());
            $table->enum('status', TherapyStatusEnum::values());
            $table->string('name');
            $table->text('background_story');
            $table->boolean('public');
            $table->boolean('anonymous');
            $table->boolean('allow_in_person');
            $table->json('payment_data')->nullable();
            $table->integer('max_sessions')->default(10);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('therapies');
    }
};
