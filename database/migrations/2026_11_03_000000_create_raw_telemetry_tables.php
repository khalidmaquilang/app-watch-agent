<?php

declare(strict_types=1);

use AppWatch\Agent\Storage\Schema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Raw telemetry tables, declared PARTITION BY RANGE (recorded_at) with one partition per day.
 * Postgres only. This migration creates the partitioned parent tables (Schema::PARTITIONED_TABLES);
 * the daily partitions are created by the initial-partitions migration and `php artisan app-watch:install`.
 * Every statement runs on the migration's connection, which `--database=` selects.
 *
 * There is intentionally NO foreign key to `environments`: a cascading delete would have to scan
 * every (large) partition, and raw data expires through partition retention anyway. Deleting an
 * environment therefore leaves its raw rows until their partitions are dropped.
 *
 * `exception_occurrences.exception_group_id` has no foreign key either, for the same reason.
 */
return new class extends Migration
{
    /**
     * The type-specific columns of each raw table (Schema::PARTITIONED_TABLES). Every table also gets
     * the common columns.
     *
     * @var array<string, string>
     */
    public const array COLUMNS = [
        'requests' => <<<'SQL'
            method varchar(16) NOT NULL,
            route varchar(1024) NULL,
            path varchar(2048) NOT NULL,
            status_code smallint NOT NULL,
            duration_ms double precision NOT NULL,
            memory_peak_bytes bigint NULL
            SQL,
        'queries' => <<<'SQL'
            connection varchar(255) NOT NULL,
            sql text NOT NULL,
            sql_hash char(40) NOT NULL,
            duration_ms double precision NOT NULL
            SQL,
        'job_runs' => <<<'SQL'
            job_class varchar(255) NOT NULL,
            queue varchar(255) NOT NULL,
            connection varchar(255) NOT NULL,
            status varchar(20) NOT NULL,
            attempt integer NOT NULL,
            duration_ms double precision NOT NULL
            SQL,
        'outgoing_requests' => <<<'SQL'
            method varchar(16) NOT NULL,
            target_host varchar(255) NOT NULL,
            url varchar(2048) NOT NULL,
            status_code smallint NULL,
            failed boolean NOT NULL,
            duration_ms double precision NOT NULL
            SQL,
        'cache_events' => <<<'SQL'
            store varchar(255) NOT NULL,
            key varchar(255) NOT NULL,
            operation varchar(20) NOT NULL,
            duration_ms double precision NULL
            SQL,
        'logs' => <<<'SQL'
            level varchar(20) NOT NULL,
            message text NOT NULL,
            context jsonb NULL
            SQL,
        'exception_occurrences' => <<<'SQL'
            exception_group_id bigint NOT NULL,
            class varchar(255) NOT NULL,
            message text NOT NULL,
            file varchar(2048) NOT NULL,
            line integer NOT NULL,
            trace jsonb NOT NULL,
            handled boolean NOT NULL
            SQL,
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        foreach (Schema::PARTITIONED_TABLES as $table) {
            $columns = self::COLUMNS[$table];

            $connection->statement(<<<SQL
                CREATE TABLE {$table} (
                    id bigint GENERATED ALWAYS AS IDENTITY,
                    environment_id bigint NOT NULL,
                    trace_id uuid NOT NULL,
                    recorded_at timestamptz(6) NOT NULL,
                    host varchar(255) NOT NULL,
                    deploy varchar(100) NULL,
                    {$columns},
                    created_at timestamptz NOT NULL DEFAULT now(),
                    PRIMARY KEY (id, recorded_at)
                ) PARTITION BY RANGE (recorded_at)
                SQL);

            $connection->statement("CREATE INDEX {$table}_environment_id_recorded_at_index ON {$table} (environment_id, recorded_at)");
            $connection->statement("CREATE INDEX {$table}_environment_id_trace_id_index ON {$table} (environment_id, trace_id)");
        }

        $connection->statement('CREATE INDEX exception_occurrences_exception_group_id_recorded_at_index ON exception_occurrences (exception_group_id, recorded_at)');
    }

    /**
     * Reverse the migrations. CASCADE also drops every partition.
     */
    public function down(): void
    {
        $connection = DB::connection($this->getConnection());

        foreach (Schema::PARTITIONED_TABLES as $table) {
            $connection->statement("DROP TABLE IF EXISTS {$table} CASCADE");
        }
    }
};
