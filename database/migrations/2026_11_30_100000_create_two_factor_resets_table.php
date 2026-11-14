<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SCRUM-424: a reset of someone's two-factor sign-in by the team waits (48 hours by default) before it applies, and can
// be cancelled meanwhile -- by the person (a link to their verified email, signing in with a code, their profile), a
// super administrator or the server command. One row per reset; ULIDs so a cancel link can't be guessed from another.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('two_factor_resets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Null: asked on the server (the command), or the administrator's account is gone.
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 16); // admin | server
            $table->string('reason')->nullable(); // a ticket number or short note -- never shown to the person
            // Two-factor as it was when asked: set up again meanwhile (a new phone), the old reset no longer applies.
            $table->timestamp('two_factor_confirmed_at_snapshot');
            $table->timestamp('due_at');
            // Whether the person must have been emailed before it applies (their email was verified when it was asked),
            // and when that email went out -- with the queue or mail down, it waits rather than applying unannounced.
            $table->boolean('mail_required')->default(false);
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 32)->nullable();
            $table->timestamps();

            $table->index(['applied_at', 'cancelled_at', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('two_factor_resets');
    }
};
