<?php

use App\Enums\DiscussionStatusEnum;
use App\Models\Session;
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
        Schema::create('discussions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->nullableMorphs('addedby');
            $table->foreignIdFor(Session::class)->nullable();
            $table->nullableMorphs('for');
            $table->text('description')->nullable();
            $table->enum('status', DiscussionStatusEnum::values());
            $table->timestamp('start_time');
            $table->timestamp('end_time');
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discussions');
    }
};
