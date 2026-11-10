<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // SCRUM-340: reports had no state at all -- nothing said whether anyone had looked at one.
    // Every existing report starts as open.
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->string('status', 20)->default('open')->after('data')->index();
            $table->string('outcome', 40)->nullable()->after('status');
            $table->text('resolution_note')->nullable()->after('outcome');
            $table->timestamp('resolved_at')->nullable()->after('resolution_note');
            $table->foreignId('resolved_by')->nullable()->after('resolved_at')->constrained('users')->nullOnDelete();
        });

        Schema::create('report_status_histories', function (Blueprint $table) {
            $table->id();
            // restrictOnDelete (security review): review history is never removed with its report.
            $table->foreignId('report_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 20); // status_change | party_recorded
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->string('outcome', 40)->nullable();
            $table->text('note')->nullable();
            $table->json('data')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_status_histories');

        Schema::table('reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'outcome', 'resolution_note', 'resolved_at']);
        });
    }
};
