<?php

use App\Enums\OrganizationCounsellorCompensationBasisEnum;
use App\Enums\OrganizationCounsellorCompensationTypeEnum;
use App\Models\OrganizationCounsellor;
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
        // Effective-dated, append-only -- a superseding row is inserted, never an update to an
        // existing one, so past compensation history stays reproducible if terms are
        // renegotiated (SCRUM-122). Deliberately not TransactionStatusHistory-shaped: this is a
        // business-config change log, not a payment-gateway status transition.
        Schema::create('organization_counsellor_compensations', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(OrganizationCounsellor::class);
            $table->enum('type', OrganizationCounsellorCompensationTypeEnum::values());
            $table->unsignedBigInteger('amount')->nullable();
            $table->string('currency', 3)->nullable();
            $table->unsignedSmallInteger('percentage')->nullable();
            $table->enum('basis', OrganizationCounsellorCompensationBasisEnum::values())->nullable();
            $table->timestamp('effective_from');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_counsellor_compensations');
    }
};
