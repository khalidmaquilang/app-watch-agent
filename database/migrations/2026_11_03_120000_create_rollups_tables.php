<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The rollup tables. Both share one schema: `rollups_minute` holds one row per environment,
     * telemetry type, key and minute; `rollups_hour` the same per hour.
     *
     * @var list<string>
     */
    private const array TABLES = ['rollups_minute', 'rollups_hour'];

    /**
     * Run the migrations. Rollups are derived (non-partitioned) summary data the dashboards chart from.
     * `environment_id` has no foreign key: telemetry lives in the client's database, where App Watch's
     * central `environments` table does not exist.
     */
    public function up(): void
    {
        foreach (self::TABLES as $table_name) {
            Schema::connection($this->getConnection())->create($table_name, function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('environment_id');
                $table->string('type');
                $table->string('key', 512);
                $table->timestampTz('bucket');
                $table->bigInteger('count')->default(0);
                $table->bigInteger('error_count')->default(0);
                $table->double('duration_sum')->nullable();
                $table->double('p50')->nullable();
                $table->double('p95')->nullable();
                $table->double('p99')->nullable();
                $table->double('max')->nullable();

                $table->unique(['environment_id', 'type', 'key', 'bucket']);
                $table->index(['environment_id', 'type', 'bucket']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::TABLES as $table_name) {
            Schema::connection($this->getConnection())->dropIfExists($table_name);
        }
    }
};
