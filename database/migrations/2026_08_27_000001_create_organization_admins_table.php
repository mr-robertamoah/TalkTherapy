<?php

use App\Enums\OrganizationAdminRoleEnum;
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
        // A pivot-with-role table, mirroring counsellor_group_therapy/group_therapy_user --
        // NOT the Administrator model's shape, since a user can be owner of one org and plain
        // admin of another simultaneously, which a single global role marker can't express.
        Schema::create('organization_admins', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Organization::class);
            $table->foreignIdFor(User::class);
            $table->enum('role', OrganizationAdminRoleEnum::values());
            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_admins');
    }
};
