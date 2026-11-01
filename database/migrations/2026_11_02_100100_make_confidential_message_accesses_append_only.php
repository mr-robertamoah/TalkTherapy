<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// SCRUM-362: the confidential-read audit log was append-only only in the model
// (ConfidentialMessageAccess::booted). These triggers make the database itself refuse to change or
// remove a row, whatever code path tries. The table has no foreign keys, so no cascade can trip them.
// MySQL with binary logging needs log_bin_trust_function_creators=1 (docker-compose.yml sets it)
// for a non-SUPER user to create a trigger -- without it this migration fails loudly, by design.
return new class extends Migration
{
    private const MESSAGE = 'confidential_message_accesses is append-only';

    public function up(): void
    {
        foreach (['UPDATE', 'DELETE'] as $event) {
            $name = 'cma_no_'.strtolower($event);

            // DDL isn't transactional in MySQL: a re-run after a half-finished one must not trip on
            // the trigger it already made.
            DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
            DB::unprepared(match (DB::getDriverName()) {
                'sqlite' => "CREATE TRIGGER {$name} BEFORE {$event} ON confidential_message_accesses BEGIN SELECT RAISE(ABORT, '".self::MESSAGE."'); END;",
                default => "CREATE TRIGGER {$name} BEFORE {$event} ON confidential_message_accesses FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MESSAGE."'",
            });
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS cma_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS cma_no_delete');
    }
};
