<?php

use App\Enums\TransactionStatusSourceEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // SCRUM-248: `source` is a native enum column -- adding RECONCILE means re-applying the full
    // current value list, as every earlier addition to a native enum column here did.
    public function up(): void
    {
        Schema::table('transaction_status_histories', function (Blueprint $table) {
            $table->enum('source', TransactionStatusSourceEnum::values())->change();
        });
    }

    public function down(): void
    {
        //
    }
};
