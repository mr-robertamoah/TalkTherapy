<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // SCRUM-340: the audit trail of every confidential message (and attachment) an admin read while
    // reviewing a report. Append-only and kept indefinitely: deliberately no foreign keys, so
    // deleting a report, message, file or admin account never deletes or rewrites a row.
    public function up(): void
    {
        Schema::create('confidential_message_accesses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('report_id')->index();
            $table->unsignedBigInteger('admin_user_id')->index();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('message_id')->nullable()->index();
            $table->unsignedBigInteger('file_id')->nullable();
            $table->text('reason');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('confidential_message_accesses');
    }
};
