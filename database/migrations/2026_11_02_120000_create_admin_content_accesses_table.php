<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCRUM-360: every time an administrator reads therapy content they could only reach because they
// are an administrator (a private therapy's or discussion's messages, replies or attachments; a
// licence or identity document), one row: who, what, when, from where. Kept indefinitely, and
// append-only -- the model refuses updates/deletes and so does the database (same triggers as
// confidential_message_accesses; MySQL with binlog needs log_bin_trust_function_creators=1).
// No foreign keys, so deleting anything else never rewrites the trail.
return new class extends Migration
{
    private const MESSAGE = 'admin_content_accesses is append-only';

    public function up(): void
    {
        // MySQL DDL isn't transactional: if the triggers below failed (e.g. the binlog setting was
        // missing), the table already exists on the re-run -- so only create it once.
        if (! Schema::hasTable('admin_content_accesses')) {
            Schema::create('admin_content_accesses', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('admin_user_id');
                $table->string('resource', 40);
                $table->string('subject_type');
                $table->unsignedBigInteger('subject_id');
                $table->unsignedBigInteger('file_id')->nullable();
                $table->unsignedInteger('page')->nullable();
                $table->string('ip', 45)->nullable();
                $table->string('user_agent', 512)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['subject_type', 'subject_id']);
                $table->index(['admin_user_id', 'created_at']);
                $table->index(['resource', 'id']);
            });
        }

        foreach (['UPDATE', 'DELETE'] as $event) {
            $name = 'aca_no_'.strtolower($event);

            DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
            DB::unprepared(match (DB::getDriverName()) {
                'sqlite' => "CREATE TRIGGER {$name} BEFORE {$event} ON admin_content_accesses BEGIN SELECT RAISE(ABORT, '".self::MESSAGE."'); END;",
                default => "CREATE TRIGGER {$name} BEFORE {$event} ON admin_content_accesses FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MESSAGE."'",
            });
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS aca_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS aca_no_delete');
        Schema::dropIfExists('admin_content_accesses');
    }
};
