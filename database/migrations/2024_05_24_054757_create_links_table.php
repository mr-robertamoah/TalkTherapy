<?php

use App\Enums\LinkStateEnum;
use App\Enums\LinkTypeEnum;
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
        Schema::create('links', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->nullableMorphs('addedby');
            $table->nullableMorphs('for');
            $table->nullableMorphs('to');
            $table->enum('type', LinkTypeEnum::values());
            $table->enum('state', LinkStateEnum::values());
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('links');
    }
};
