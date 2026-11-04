<?php

declare(strict_types=1);

use AppWatch\Agent\Storage\Schema as TelemetrySchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The installed telemetry schema versions. App Watch reads the highest version before it reads a client
 * database. Every later agent migration that changes the schema inserts the next version in the same
 * migration.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection($this->getConnection())->create(TelemetrySchema::VERSIONS_TABLE, function (Blueprint $table): void {
            $table->unsignedInteger('version')->primary();
            $table->timestampTz('installed_at');
        });

        DB::connection($this->getConnection())->table(TelemetrySchema::VERSIONS_TABLE)->insert([
            'version' => 1,
            'installed_at' => now('UTC'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists(TelemetrySchema::VERSIONS_TABLE);
    }
};
