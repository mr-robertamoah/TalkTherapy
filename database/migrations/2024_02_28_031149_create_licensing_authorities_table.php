<?php

use App\Enums\LicensingAuthorityTypeEnum;
use App\Enums\LicensingTypeEnum;
use App\Models\User;
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
        Schema::create('licensing_authorities', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('addedby');
            $table->string('name');
            $table->string('country')->nullable();
            $table->enum('type', LicensingAuthorityTypeEnum::values());
            $table->enum('license_type', LicensingTypeEnum::values());
            $table->string('other')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('license_format')->nullable();
            $table->boolean('is_public')->default(true);
            $table->text('about')->nullable();
            $table->boolean('validated')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('licensing_authorities');
    }
};
