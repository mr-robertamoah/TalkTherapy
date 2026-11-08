<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCRUM-33: the emergency numbers the "I need help now" dialog shows, per country (ISO alpha-2, the
// codes in resources/data/countries.json). Every row is verified by a named person against a source
// -- nothing is shown without it, and numbers are never invented. Super administrators manage them;
// every change is kept in emergency_number_events, which is append-only (same triggers as
// counsellor_verification_events; MySQL with binlog needs log_bin_trust_function_creators=1).
return new class extends Migration
{
    private const MESSAGE = 'emergency_number_events is append-only';

    public function up(): void
    {
        if (! Schema::hasTable('emergency_numbers')) {
            Schema::create('emergency_numbers', function (Blueprint $table) {
                $table->id();
                $table->char('country_code', 2);
                $table->string('label', 100);
                $table->string('number', 30);
                $table->string('notes', 200)->nullable();
                $table->string('source_url', 500);
                $table->string('verified_by', 120);
                $table->date('verified_at');
                $table->unsignedTinyInteger('position')->default(0);
                $table->unsignedBigInteger('updated_by_id')->nullable();
                $table->timestamps();

                $table->index(['country_code', 'position']);
            });
        }

        if (! Schema::hasTable('emergency_number_events')) {
            Schema::create('emergency_number_events', function (Blueprint $table) {
                $table->id();
                // No foreign keys: deleting a number never rewrites its history.
                $table->unsignedBigInteger('emergency_number_id');
                $table->string('action', 10);
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->json('values');
                $table->timestamp('created_at')->useCurrent();

                $table->index(['emergency_number_id', 'id']);
            });
        }

        foreach (['UPDATE', 'DELETE'] as $event) {
            $name = 'ene_no_'.strtolower($event);

            DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
            DB::unprepared(match (DB::getDriverName()) {
                'sqlite' => "CREATE TRIGGER {$name} BEFORE {$event} ON emergency_number_events BEGIN SELECT RAISE(ABORT, '".self::MESSAGE."'); END;",
                default => "CREATE TRIGGER {$name} BEFORE {$event} ON emergency_number_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MESSAGE."'",
            });
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS ene_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS ene_no_delete');
        Schema::dropIfExists('emergency_number_events');
        Schema::dropIfExists('emergency_numbers');
    }
};
