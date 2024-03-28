<?php

use App\Models\GroupTherapy;
use App\Models\User;
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
        Schema::create('group_therapy_user', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(GroupTherapy::class);
            $table->foreignIdFor(User::class);
            $table->boolean('anonymous');
            $table->text('background_story')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('group_therapy_user');
    }
};
