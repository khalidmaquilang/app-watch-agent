<?php

declare(strict_types=1);

use AppWatch\Agent\Storage\PartitionDdl;
use AppWatch\Agent\Storage\Schema;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the daily partitions of the raw telemetry tables for today … today +
 * `app-watch.partitions_ahead_days` (UTC) on the migration's connection, so inserts work right after
 * migrating. `php artisan app-watch:install` creates them again (a no-op for existing ones) on every run.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        (new PartitionDdl)->createDaily(
            DB::connection($this->getConnection()),
            CarbonImmutable::now('UTC'),
            max(0, (int) config('app-watch.partitions_ahead_days', Schema::DEFAULT_PARTITIONS_AHEAD_DAYS)),
        );
    }

    /**
     * Reverse the migrations. Intentionally a no-op: dropping partitions would delete telemetry,
     * and rolling back the raw-tables migration drops every partition with CASCADE anyway.
     */
    public function down(): void {}
};
