<?php

declare(strict_types=1);

namespace AppWatch\Agent\Storage;

/**
 * The telemetry schema the agent installs in the client's database (`php artisan app-watch:install`).
 */
final class Schema
{
    /**
     * The raw telemetry tables, declared PARTITION BY RANGE (recorded_at) with one partition per UTC day.
     *
     * @var list<string>
     */
    public const array PARTITIONED_TABLES = [
        'requests', 'queries', 'job_runs', 'outgoing_requests', 'cache_events', 'logs', 'exception_occurrences',
    ];

    /**
     * The table holding one row per installed schema version. Every agent migration that changes the
     * schema inserts the next version in the same migration.
     */
    public const string VERSIONS_TABLE = 'app_watch_schema_versions';

    /**
     * The default number of days ahead of today the daily partitions are created for.
     */
    public const int DEFAULT_PARTITIONS_AHEAD_DAYS = 7;

    /**
     * The agent's migrations directory.
     */
    public static function migrationsPath(): string
    {
        return dirname(__DIR__, 2).'/database/migrations';
    }
}
