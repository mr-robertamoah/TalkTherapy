<?php

use App\Enums\OrganizationMemberSourceEnum;
use App\Enums\OrganizationMemberStatusEnum;
use App\Models\Organization;
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
        Schema::create('organization_members', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Organization::class);
            $table->foreignIdFor(User::class);
            $table->enum('status', OrganizationMemberStatusEnum::values());
            $table->enum('source', OrganizationMemberSourceEnum::values());
            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_members');
    }
};
