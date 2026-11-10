<?php

use App\Enums\OrganizationMemberBillingModeEnum;
use App\Enums\TherapyPerPaymentEnum;
use App\Models\OrganizationMember;
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
        // Effective-dated, append-only -- same shape as organization_counsellor_compensations
        // (TT-6.4b): a changed billing config inserts a new row, never mutates an existing one.
        Schema::create('organization_member_billing_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(OrganizationMember::class);
            $table->enum('mode', OrganizationMemberBillingModeEnum::values());
            $table->enum('per', TherapyPerPaymentEnum::values())->nullable();
            $table->boolean('include_group_therapies');
            $table->timestamp('effective_from');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_member_billing_configs');
    }
};
