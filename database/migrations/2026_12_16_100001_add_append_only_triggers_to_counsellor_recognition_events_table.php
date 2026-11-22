<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // SCRUM-475 (security review): counsellor_recognition_events (SCRUM-473) is append-only in the model
    // (CounsellorRecognitionEvent uses AppendOnly) but wasn't in the database. The same triggers as
    // counsellor_verification_events; MySQL with binlog needs log_bin_trust_function_creators=1.
    //
    // MySQL doesn't fire triggers for foreign-key actions, so a recognition's events still go with it when the
    // recognition is deleted (it cascades from the counsellor, e.g. SCRUM-134's purge), and deleting a user still nulls
    // user_id. SQLite does fire them, so there a cascade onto these rows is refused.
    private const MESSAGE = 'counsellor_recognition_events is append-only';

    public function up(): void
    {
        foreach (['UPDATE', 'DELETE'] as $event) {
            $name = 'cre_no_'.strtolower($event);

            DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
            DB::unprepared(match (DB::getDriverName()) {
                'sqlite' => "CREATE TRIGGER {$name} BEFORE {$event} ON counsellor_recognition_events BEGIN SELECT RAISE(ABORT, '".self::MESSAGE."'); END;",
                default => "CREATE TRIGGER {$name} BEFORE {$event} ON counsellor_recognition_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MESSAGE."'",
            });
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS cre_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS cre_no_delete');
    }
};
