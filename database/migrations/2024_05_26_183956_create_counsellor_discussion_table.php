<?php

use App\Models\Counsellor;
use App\Models\Discussion;
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
        Schema::create('counsellor_discussion', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Counsellor::class);
            $table->foreignIdFor(Discussion::class);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('counsellor_discussion');
    }
};
