<?php

use App\Enums\OrganizationInvoiceStatusEnum;
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
        // TT-7.3b-e/SCRUM-236: aggregated, post-paid retainer billing (Decision 3, SCRUM-230
        // review) -- ONE row per org per settlement period, lazily found-or-created on the FIRST
        // retainer-covered session that occurs in that period (never pre-created for every org
        // up front, so an org with zero retainer sessions in a period simply never gets a row).
        // `Transaction::for()` points at this model (architect decision) rather than a synthetic
        // per-session Transaction, since there was never a per-session charge event at the
        // gateway to reconcile against -- the actual charge only ever happens once, at
        // settlement, for the whole period.
        Schema::create('organization_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Organization::class)->constrained()->cascadeOnDelete();
            // Part of the lookup/uniqueness key, not just a stored fact (architect finding): an
            // org's retainer-covered counsellors could plausibly have listed rates in different
            // currencies, and mixing currencies into one invoice.amount would silently corrupt
            // the total -- one invoice per (org, currency, period) instead.
            $table->string('currency', 3);
            $table->date('period_start');
            $table->date('period_end');
            $table->enum('status', OrganizationInvoiceStatusEnum::values())->default(OrganizationInvoiceStatusEnum::open->value);
            // Null until a settlement attempt sums the period's lines -- never the client-facing
            // listed price, the sum of what TT-7.3b-b0's compensation math + platform fee say is
            // actually owed per line (mirrors ChargeOrganizationForModelAction's own "actual
            // cost" design for the pay-per-use case).
            $table->unsignedBigInteger('amount')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'currency', 'period_start'], 'organization_invoices_org_currency_period_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_invoices');
    }
};
