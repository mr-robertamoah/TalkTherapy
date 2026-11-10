<?php

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
        // TT-7.3b-a/SCRUM-231: one row per organization (unique) -- mirrors
        // counsellor_payout_accounts' convention exactly (a single current instrument, replaced
        // in place on re-registration, no history table since no later ticket needs to query past
        // instruments). Paystack has no "just verify this card" call -- the row's mere existence
        // means a real (small, nominal) verification charge actually succeeded through Paystack,
        // the same way counsellor_payout_accounts' row existence already means "resolved and
        // verified with Paystack".
        Schema::create('organization_payment_instruments', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Organization::class)->unique()->constrained()->cascadeOnDelete();
            // Paystack's reusable authorization code -- what a future pay-per-use/retainer charge
            // (TT-7.3b-c/-e) actually charges against via PaystackClient::chargeAuthorization(),
            // rather than re-running checkout each time. Unique for the same reason
            // counsellor_payout_accounts.recipient_code is: Paystack wouldn't issue the same code
            // twice, but this also guards against a duplicate-capture bug attaching one
            // authorization to two organizations.
            $table->string('authorization_code')->unique();
            // Never the raw card number -- e.g. "**** 4242". Only ever sent to Paystack
            // transiently (the verification charge) and never persisted here.
            $table->string('masked_card_number');
            $table->string('card_type')->nullable();
            $table->string('bank')->nullable();
            $table->string('exp_month', 2)->nullable();
            $table->string('exp_year', 4)->nullable();
            $table->string('currency', 3);
            // The small nominal amount (minor units) the verification charge actually collected --
            // owed back to the org as a credit against its first real invoice once TT-7.3b-e's
            // invoicing exists, never silently kept. Null once that credit has been applied
            // (TT-7.3b-e's job to consume and clear this).
            $table->unsignedBigInteger('pending_credit_amount')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_payment_instruments');
    }
};
