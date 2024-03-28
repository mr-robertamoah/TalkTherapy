<?php

use App\Enums\CounsellorGroupTherapyStateEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
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
        Schema::create('counsellor_group_therapy', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(GroupTherapy::class);
            $table->foreignIdFor(Counsellor::class);
            $table->enum('state', CounsellorGroupTherapyStateEnum::values());
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('counsellor_group_therapy');
    }
};
