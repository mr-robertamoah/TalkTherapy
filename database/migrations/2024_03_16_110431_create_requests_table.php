<?php

use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
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
        Schema::create('requests', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('from');
            $table->nullableMorphs('to');
            $table->nullableMorphs('for');
            $table->enum('status', RequestStatusEnum::values())->default(RequestStatusEnum::pending->value);
            $table->enum('type', RequestTypeEnum::values());
            $table->json('data');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('requests');
    }
};
