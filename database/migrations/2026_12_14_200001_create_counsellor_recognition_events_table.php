<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // TT-7.11d1/SCRUM-473: every change to a recognition -- a decision (approved, skipped, added) or a change of
    // consent -- and who made it (null when nobody in particular did). App\Enums\CounsellorRecognitionEventEnum.
    public function up(): void
    {
        Schema::create('counsellor_recognition_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recognition_id')->constrained('counsellor_recognitions')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 30);
            $table->json('data')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('counsellor_recognition_events');
    }
};
