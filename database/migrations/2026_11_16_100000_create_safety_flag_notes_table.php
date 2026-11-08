<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCRUM-32 (TT-4.3b): what the handling administrator writes on a safety flag -- notes, and the actions
// they took (from a fixed list, with optional text). Kept apart from the append-only safety_flag_events
// (which only point at a row here) so the text can be cleared when the client's account is anonymised
// (the user's default: free text cleared on closure, as for safeguarding exceptions -- TT-4.3c). The
// content is encrypted by the model. Triggers (architect review, like its sibling tables): a note is never
// deleted, and the only change allowed is clearing its content.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safety_flag_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('safety_flag_id');
            $table->unsignedBigInteger('author_user_id')->nullable();
            $table->string('kind', 10);
            $table->string('action_type', 40)->nullable();
            $table->text('content')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['safety_flag_id', 'id']);
        });

        $message = 'safety_flag_notes are never deleted, and only their content can be cleared';
        $sqlite = DB::getDriverName() === 'sqlite';

        DB::unprepared('DROP TRIGGER IF EXISTS sfn_no_delete');
        DB::unprepared($sqlite
            ? "CREATE TRIGGER sfn_no_delete BEFORE DELETE ON safety_flag_notes BEGIN SELECT RAISE(ABORT, '{$message}'); END;"
            : "CREATE TRIGGER sfn_no_delete BEFORE DELETE ON safety_flag_notes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'");

        DB::unprepared('DROP TRIGGER IF EXISTS sfn_only_clear');
        DB::unprepared($sqlite
            ? "CREATE TRIGGER sfn_only_clear BEFORE UPDATE ON safety_flag_notes WHEN NEW.content IS NOT NULL OR NEW.safety_flag_id IS NOT OLD.safety_flag_id OR NEW.author_user_id IS NOT OLD.author_user_id OR NEW.kind IS NOT OLD.kind OR NEW.action_type IS NOT OLD.action_type OR NEW.created_at IS NOT OLD.created_at BEGIN SELECT RAISE(ABORT, '{$message}'); END;"
            : "CREATE TRIGGER sfn_only_clear BEFORE UPDATE ON safety_flag_notes FOR EACH ROW BEGIN IF NEW.content IS NOT NULL OR NOT (NEW.safety_flag_id <=> OLD.safety_flag_id) OR NOT (NEW.author_user_id <=> OLD.author_user_id) OR NOT (NEW.kind <=> OLD.kind) OR NOT (NEW.action_type <=> OLD.action_type) OR NOT (NEW.created_at <=> OLD.created_at) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS sfn_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS sfn_only_clear');
        Schema::dropIfExists('safety_flag_notes');
    }
};
