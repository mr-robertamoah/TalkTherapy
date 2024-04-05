<?php

use App\Models\Session;
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
        Schema::create('therapy_topic_session', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(TherapyTopic::class);
            $table->foreignIdFor(Session::class);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('therapy_topic_session');
    }
};
