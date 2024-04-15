<?php

use App\Enums\MessageStatusEnum;
use App\Enums\MessageTypeEnum;
use App\Models\Message;
use App\Models\TherapyTopic;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('from');
            $table->nullableMorphs('to');
            $table->nullableMorphs('for');
            $table->enum('type', MessageTypeEnum::values());
            $table->foreignIdFor(Message::class)->nullable();
            $table->foreignIdFor(TherapyTopic::class)->nullable();
            $table->text('content')->nullable();
            $table->enum('status', MessageStatusEnum::values());
            $table->boolean('confidential')->default(false);
            $table->text('deleted_for')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
