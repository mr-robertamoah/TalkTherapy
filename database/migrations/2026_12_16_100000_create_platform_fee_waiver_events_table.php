<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // TT-7.11e/SCRUM-475: every change to a commission-free period -- platform-wide or one counsellor's -- and who made
    // it (App\Enums\PlatformFeeWaiverEventEnum: created, ended, deleted; user null when nobody in particular did, e.g.
    // a purge). Written only by PlatformFeeWaiverService.
    //
    // platform_fee_waiver_id deliberately has no foreign key, so a deleted period's history stays (its data keeps the
    // dates and the reason). counsellor_id has none either, for the same reason: null is platform-wide.
    //
    // Append-only: the model refuses updates and deletes, and so does the database (the same triggers as
    // counsellor_verification_events; MySQL with binlog needs log_bin_trust_function_creators=1). MySQL doesn't fire
    // triggers for foreign-key actions, so deleting a user still nulls user_id there.
    private const MESSAGE = 'platform_fee_waiver_events is append-only';

    public function up(): void
    {
        // MySQL DDL isn't transactional: only create the table once if the triggers fail on a run.
        if (! Schema::hasTable('platform_fee_waiver_events')) {
            Schema::create('platform_fee_waiver_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('platform_fee_waiver_id')->index();
                $table->unsignedBigInteger('counsellor_id')->nullable()->index();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('event', 30);
                $table->json('data')->nullable();
                $table->timestamps();
            });
        }

        foreach (['UPDATE', 'DELETE'] as $event) {
            $name = 'pfwe_no_'.strtolower($event);

            DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
            DB::unprepared(match (DB::getDriverName()) {
                'sqlite' => "CREATE TRIGGER {$name} BEFORE {$event} ON platform_fee_waiver_events BEGIN SELECT RAISE(ABORT, '".self::MESSAGE."'); END;",
                default => "CREATE TRIGGER {$name} BEFORE {$event} ON platform_fee_waiver_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MESSAGE."'",
            });
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS pfwe_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS pfwe_no_delete');
        Schema::dropIfExists('platform_fee_waiver_events');
    }
};
