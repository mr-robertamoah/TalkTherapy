<?php

use App\Enums\OrganizationCounsellorSourceEnum;
use App\Enums\OrganizationCounsellorStatusEnum;
use App\Models\Counsellor;
use App\Models\Organization;
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
        Schema::create('organization_counsellors', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Organization::class);
            $table->foreignIdFor(Counsellor::class);
            $table->enum('status', OrganizationCounsellorStatusEnum::values());
            $table->enum('source', OrganizationCounsellorSourceEnum::values());
            $table->timestamps();

            $table->unique(['organization_id', 'counsellor_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_counsellors');
    }
};
