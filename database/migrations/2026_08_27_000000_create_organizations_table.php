<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // SQLite (the test suite's driver, per phpunit.xml) has no ALTER TABLE ADD CONSTRAINT
        // -- a CHECK constraint can only be declared inline at CREATE TABLE time there. MySQL
        // (this app's real driver) supports adding it afterward. Both paths enforce the exact
        // same invariant; only how it's attached differs.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement('
                CREATE TABLE organizations (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name VARCHAR NOT NULL,
                    legal_name VARCHAR NULL,
                    registration_number VARCHAR NOT NULL,
                    description TEXT NULL,
                    email VARCHAR NULL,
                    phone VARCHAR NULL,
                    logo_id INTEGER NULL REFERENCES files(id) ON DELETE SET NULL,
                    is_provider TINYINT(1) NOT NULL DEFAULT 0,
                    is_consumer TINYINT(1) NOT NULL DEFAULT 0,
                    verified_at DATETIME NULL,
                    created_at DATETIME NULL,
                    updated_at DATETIME NULL,
                    deleted_at DATETIME NULL,
                    CHECK (is_provider = 1 OR is_consumer = 1)
                )
            ');

            return;
        }

        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('registration_number');
            $table->text('description')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->foreignId('logo_id')->nullable()->constrained('files')->nullOnDelete();
            $table->boolean('is_provider')->default(false);
            $table->boolean('is_consumer')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Enforced at the DB level, not just form validation, per SCRUM-119's acceptance
        // criteria -- a raw insert/update bypassing Eloquent must still be rejected.
        DB::statement(
            'ALTER TABLE organizations ADD CONSTRAINT organizations_is_provider_or_consumer_check CHECK (is_provider = 1 OR is_consumer = 1)'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
