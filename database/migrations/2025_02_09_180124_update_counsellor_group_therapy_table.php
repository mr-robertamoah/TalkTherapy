<?php

use App\Enums\CounsellorGroupTherapyRoleEnum;
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
        Schema::table('counsellor_group_therapy', function (Blueprint $table) {
            $table->enum('role', CounsellorGroupTherapyRoleEnum::values());
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('counsellor_group_therapy', function (Blueprint $table) {
            $table->dropColumn(['role']);
        });
    }
};
