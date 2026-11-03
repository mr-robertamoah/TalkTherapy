<?php

use App\Enums\CounsellorPayoutStatusSourceEnum;
use App\Enums\RefundStatusSourceEnum;
use App\Enums\TransactionStatusSourceEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // SCRUM-378: each `source` is a native enum column -- adding RETRY_LOOKUP means re-applying the
    // full current value list, as 2026_11_04_100000 did for RECONCILE. Only MySQL/MariaDB have native
    // enums; elsewhere (the SQLite test database) the create-table migrations already build these
    // columns from the current enum values, so there is nothing to change.
    private const COLUMNS = [
        'transaction_status_histories' => TransactionStatusSourceEnum::class,
        'counsellor_payout_status_histories' => CounsellorPayoutStatusSourceEnum::class,
        'refund_status_histories' => RefundStatusSourceEnum::class,
    ];

    public function up(): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (self::COLUMNS as $table => $enum) {
            Schema::table($table, function (Blueprint $table) use ($enum) {
                $table->enum('source', $enum::values())->change();
            });
        }
    }

    // Deliberately empty, like the earlier enum additions: narrowing the list again would fail (or
    // truncate) on any row already recorded with RETRY_LOOKUP.
    public function down(): void
    {
        //
    }
};
