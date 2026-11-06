<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCRUM-31: each counsellor's verification history -- verified, re-verified, rejected, a reminder
// sent, lapsed, revoked (CounsellorVerificationEventEnum): who (null: the system), when, the request
// and licences (ids only, never numbers or documents), the reason and the end date it set. Kept
// indefinitely (the user's decision: a professional-standing record), admins only, and append-only
// -- the model refuses updates/deletes and so does the database (same triggers as
// admin_content_accesses; MySQL with binlog needs log_bin_trust_function_creators=1). No foreign keys,
// so deleting or purging a counsellor never rewrites the trail. dedupe_key (unique) is how a sweep
// records "already done" -- a reminder or a lapse is written once per verification end date.
return new class extends Migration
{
    private const MESSAGE = 'counsellor_verification_events is append-only';

    public function up(): void
    {
        // MySQL DDL isn't transactional: only create the table once if the triggers fail on a run.
        if (! Schema::hasTable('counsellor_verification_events')) {
            Schema::create('counsellor_verification_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('counsellor_id');
                $table->string('event', 20);
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->unsignedBigInteger('request_id')->nullable();
                $table->json('license_ids')->nullable();
                $table->text('reason')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->unsignedSmallInteger('window_days')->nullable();
                $table->string('dedupe_key', 80)->nullable()->unique();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['counsellor_id', 'id']);
                $table->index(['event', 'created_at']);
            });
        }

        foreach (['UPDATE', 'DELETE'] as $event) {
            $name = 'cve_no_'.strtolower($event);

            DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
            DB::unprepared(match (DB::getDriverName()) {
                'sqlite' => "CREATE TRIGGER {$name} BEFORE {$event} ON counsellor_verification_events BEGIN SELECT RAISE(ABORT, '".self::MESSAGE."'); END;",
                default => "CREATE TRIGGER {$name} BEFORE {$event} ON counsellor_verification_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MESSAGE."'",
            });
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS cve_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS cve_no_delete');
        Schema::dropIfExists('counsellor_verification_events');
    }
};
