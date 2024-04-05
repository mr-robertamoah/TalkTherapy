<?php

use App\Models\Counsellor;
use App\Models\Therapy;
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
        Schema::create('therapy_topics', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Counsellor::class);
            $table->foreignIdFor(Therapy::class);
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('therapy_topics');
    }
};
