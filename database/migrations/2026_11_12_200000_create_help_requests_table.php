<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCRUM-33: the quick help chat.
// - counsellors.urgent_help_available_until: "available for urgent help" until then (it lapses on
//   its own -- no sweep needed).
// - therapies.is_help_chat / sessions.is_help_chat: the private, free therapy and its one session
//   created when a counsellor accepts a help request, so every other list and count can leave them
//   out (withoutHelpChats()).
// - help_requests: one per "I need help now". active_user_id is set (unique) while the request is
//   waiting or connected and nulled when it ends, so a person can only ever have one live request,
//   enforced by the database. Snapshots of the person at the time (minor, country, anonymous).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('counsellors', function (Blueprint $table) {
            $table->timestamp('urgent_help_available_until')->nullable()->index();
        });

        Schema::table('therapies', function (Blueprint $table) {
            $table->boolean('is_help_chat')->default(false)->index();
        });

        Schema::table('sessions', function (Blueprint $table) {
            $table->boolean('is_help_chat')->default(false)->index();
        });

        Schema::create('help_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('active_user_id')->nullable()->unique();
            $table->string('status', 20);
            $table->boolean('anonymous')->default(false);
            $table->boolean('was_minor')->default(false);
            $table->boolean('was_suspended')->default(false);
            $table->string('country_code', 2)->nullable();
            $table->string('note', 200)->nullable();
            $table->unsignedBigInteger('counsellor_id')->nullable()->index();
            $table->unsignedBigInteger('therapy_id')->nullable();
            $table->unsignedBigInteger('session_id')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('ended_by', 20)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_requests');

        Schema::table('sessions', fn (Blueprint $table) => $table->dropColumn('is_help_chat'));
        Schema::table('therapies', fn (Blueprint $table) => $table->dropColumn('is_help_chat'));
        Schema::table('counsellors', fn (Blueprint $table) => $table->dropColumn('urgent_help_available_until'));
    }
};
