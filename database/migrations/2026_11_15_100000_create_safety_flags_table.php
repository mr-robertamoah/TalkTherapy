<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCRUM-32: a counsellor's safety flag (recommending specialist or inpatient care) and its history.
// - safety_flags: never deleted (a trigger refuses it). `flaggable` is the Therapy or GroupTherapy (an
//   urgent help chat is a Therapy). open_client_key is set while the flag is open or acknowledged and
//   cleared when it's withdrawn or resolved -- unique, so there is only ever one open flag per client
//   per therapy, enforced by the database (the help_requests.active_user_id pattern). The reason,
//   notes and hold reasons are encrypted by the model. was_minor / had_guardian / anonymous /
//   country_code are snapshots, so the administrators' list never loads the therapy or the client.
//   The notice columns are used by TT-4.3c, the handling ones by TT-4.3b; they're created here so one
//   migration carries the table. Note text added in TT-4.3b must live somewhere it can be cleared on
//   account anonymisation -- not only in the append-only events (architect review).
// - safety_flag_events: append-only (UPDATE and DELETE refused by triggers, as for
//   emergency_number_events) -- raised, acknowledged, reassigned, notes, actions, notices, withdrawn,
//   resolved. Who looked at the client's identity or the reason is in admin_content_accesses.
// Production needs log_bin_trust_function_creators=1 for the triggers. Prefer a code-only rollback:
// rolling this back would drop the safety record.
return new class extends Migration
{
    private const EVENTS_MESSAGE = 'safety_flag_events is append-only';

    private const FLAGS_MESSAGE = 'safety_flags are never deleted';

    public function up(): void
    {
        Schema::create('safety_flags', function (Blueprint $table) {
            $table->id();
            // What administrators' pages and links use -- not the sequential id, whose gaps would tell an
            // involved administrator that a flag they can't see exists (security review).
            $table->ulid('public_id')->unique();
            $table->string('flaggable_type');
            $table->unsignedBigInteger('flaggable_id');
            $table->string('kind', 12);
            $table->unsignedBigInteger('client_user_id')->index();
            $table->unsignedBigInteger('membership_id')->nullable();
            $table->unsignedBigInteger('counsellor_id')->index();
            $table->string('urgency', 12);
            // Nullable: cleared, like the other free text, when the client's account is anonymised (TT-4.3c).
            $table->text('reason')->nullable();
            $table->string('discussed_with_client', 14);
            $table->boolean('emergency_services_contacted')->default(false);
            $table->text('emergency_services_note')->nullable();
            $table->string('status', 14);
            $table->string('open_client_key', 191)->nullable()->unique();

            $table->unsignedBigInteger('handler_user_id')->nullable()->index();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('realerted_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->text('withdrawn_reason')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('outcome', 40)->nullable();
            $table->text('outcome_note')->nullable();

            $table->boolean('was_minor')->default(false);
            $table->boolean('had_guardian')->default(false);
            $table->boolean('anonymous')->default(false);
            $table->char('country_code', 2)->nullable();

            $table->string('client_notice_state', 14)->default('PENDING'); // SafetyFlagNoticeStateEnum
            $table->text('client_notice_hold_reason')->nullable();
            $table->timestamp('client_notice_sent_at')->nullable();
            $table->string('guardian_notice_state', 16)->default('NOT_APPLICABLE');
            $table->text('guardian_withhold_reason')->nullable();
            $table->unsignedBigInteger('guardian_decided_by_id')->nullable();
            $table->timestamp('guardian_notice_sent_at')->nullable();

            $table->timestamps();

            $table->index(['flaggable_type', 'flaggable_id']);
            // The badge and lists filter by status; TT-4.3b's re-alert sweep by status and age.
            $table->index(['status', 'created_at']);
        });

        Schema::create('safety_flag_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('safety_flag_id');
            $table->string('action', 30);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->text('data')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['safety_flag_id', 'id']);
        });

        foreach (['UPDATE', 'DELETE'] as $event) {
            $this->refuse('sfe_no_'.strtolower($event), $event, 'safety_flag_events', self::EVENTS_MESSAGE);
        }
        $this->refuse('sf_no_delete', 'DELETE', 'safety_flags', self::FLAGS_MESSAGE);
    }

    public function down(): void
    {
        foreach (['sfe_no_update', 'sfe_no_delete', 'sf_no_delete'] as $name) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
        }
        Schema::dropIfExists('safety_flag_events');
        Schema::dropIfExists('safety_flags');
    }

    private function refuse(string $name, string $event, string $table, string $message): void
    {
        DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
        DB::unprepared(match (DB::getDriverName()) {
            'sqlite' => "CREATE TRIGGER {$name} BEFORE {$event} ON {$table} BEGIN SELECT RAISE(ABORT, '{$message}'); END;",
            default => "CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'",
        });
    }
};
