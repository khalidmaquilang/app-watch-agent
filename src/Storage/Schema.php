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
        'requests', 'queries', 'job_runs', 'outgoing_requests', 'cache_events', 'logs', 'exception_occurrences', 'command_runs',
    ];

    /**
     * The table holding one row per installed schema version. The install migration records
     * CURRENT_VERSION.
     */
    public const string VERSIONS_TABLE = 'app_watch_schema_versions';

    /**
     * The telemetry schema version this agent installs. The original migrations are edited in place
     * (nothing is live yet) and the versions table records this value on install. App Watch refuses to
     * read a database whose highest recorded version is below its supported version.
     *
     * - 1: initial schema.
     * - 2: `exception_groups.resolved_at` removed; issue status lives in App Watch's central `issues` table.
     * - 3: `command_runs` added (Artisan command runs).
     */
    public const int CURRENT_VERSION = 3;

    /**
     * The default number of days ahead of today the daily partitions are created for.
     */
    public const int DEFAULT_PARTITIONS_AHEAD_DAYS = 7;

    /**
     * The default number of days of raw telemetry kept before its daily partitions are dropped.
     */
    public const int DEFAULT_RETENTION_DAYS = 14;

    /**
     * The agent's migrations directory.
     */
    public static function migrationsPath(): string
    {
        return dirname(__DIR__, 2).'/database/migrations';
    }
}
