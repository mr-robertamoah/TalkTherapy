<?php

use App\Enums\PayoutDestinationTypeEnum;
use App\Models\Counsellor;
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
        // TT-7.6a/SCRUM-225: one row per counsellor (unique) -- a counsellor has exactly one
        // current payout destination at a time; changing it replaces this row in place (via
        // CreateCounsellorPayoutDestinationAction) rather than accumulating a history, since
        // unlike the earnings ledger there's no later ticket that needs to query past
        // destinations. The row's mere existence already means "resolved and verified with
        // Paystack" -- if either the account-resolve or recipient-creation call had failed,
        // nothing would have been persisted, so there's no separate verified_at column.
        Schema::create('counsellor_payout_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Counsellor::class)->unique()->constrained()->cascadeOnDelete();
            $table->enum('type', PayoutDestinationTypeEnum::values());
            // Paystack's bank/mobile-money-provider code -- not the same as this platform's own
            // currency codes.
            $table->string('bank_code');
            $table->string('bank_name');
            // The account holder name Paystack's own resolve call returned -- shown back to the
            // counsellor as confirmation, never taken as freeform input.
            $table->string('account_name');
            // Never the raw account number -- e.g. "**** 1234". The raw number is only ever sent
            // to Paystack (resolve + create-recipient calls) and never persisted here.
            $table->string('masked_account_number');
            // Paystack's Transfer Recipient code -- what TT-7.6c's payout execution actually
            // transfers against. Unique: Paystack itself would never issue the same code twice,
            // but this also protects against a duplicate-recipient-creation bug silently
            // attaching one recipient to two counsellors.
            $table->string('recipient_code')->unique();
            $table->string('currency', 3);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('counsellor_payout_accounts');
    }
};
