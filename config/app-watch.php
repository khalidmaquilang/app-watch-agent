<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Master switch. The agent also stays silent while no environment id or
    | telemetry database connection is configured.
    |
    */

    'enabled' => (bool) env('APP_WATCH_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | The id of this app's environment, shown on the App Watch monitor's
    | Settings -> Environments page. It is stored on every telemetry row, so
    | several projects can share one database. Null while unset; anything that
    | is not a positive integer keeps the agent silent.
    |
    */

    'environment_id' => env('APP_WATCH_ENVIRONMENT_ID') === null ? null : (int) env('APP_WATCH_ENVIRONMENT_ID'),

    /*
    |--------------------------------------------------------------------------
    | Telemetry Database
    |--------------------------------------------------------------------------
    |
    | The name of the Laravel database connection the agent writes telemetry
    | to. Unless the host app already defines a connection with this name, the
    | agent registers it as a pgsql connection from the `database` settings
    | (when `database.database` is set). Point it at an existing connection,
    | e.g. "pgsql", to reuse the app's own database; `database` is then ignored.
    |
    */

    'connection' => env('APP_WATCH_DB_CONNECTION', 'app_watch'),

    'database' => [
        'host' => env('APP_WATCH_DB_HOST'),
        'port' => (int) env('APP_WATCH_DB_PORT', 5432),
        'database' => env('APP_WATCH_DB_DATABASE'),
        'username' => env('APP_WATCH_DB_USERNAME'),
        'password' => env('APP_WATCH_DB_PASSWORD'),
        'sslmode' => env('APP_WATCH_DB_SSLMODE', 'require'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Raw Telemetry Retention
    |--------------------------------------------------------------------------
    |
    | How many days of raw telemetry are kept: daily partitions whose upper
    | bound is at or before the start of (today - retention_days) in UTC are
    | dropped. `partitions_ahead_days` future UTC days get a partition on top
    | of today, because Postgres rejects inserts into a day without one.
    |
    */

    'retention_days' => (int) env('APP_WATCH_RETENTION_DAYS', 14),

    'partitions_ahead_days' => (int) env('APP_WATCH_PARTITIONS_AHEAD_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Rollups
    |--------------------------------------------------------------------------
    |
    | How many already-closed minutes the minute rollup re-aggregates on every
    | run (so late events are still counted), and how many days of minute and
    | hour rollups are kept before they are pruned.
    |
    */

    'rollups' => [
        'grace_minutes' => (int) env('APP_WATCH_ROLLUPS_GRACE_MINUTES', 2),
        'minute_retention_days' => (int) env('APP_WATCH_ROLLUPS_MINUTE_RETENTION_DAYS', 7),
        'hour_retention_days' => (int) env('APP_WATCH_ROLLUPS_HOUR_RETENTION_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Partitioned Raw Tables
    |--------------------------------------------------------------------------
    |
    | The raw telemetry tables declared PARTITION BY RANGE (recorded_at). These
    | names are interpolated into partition DDL, so they come from here only.
    |
    */

    'tables' => [
        'requests',
        'queries',
        'job_runs',
        'outgoing_requests',
        'cache_events',
        'logs',
        'exception_occurrences',
    ],

    /*
    |--------------------------------------------------------------------------
    | Local Daemon
    |--------------------------------------------------------------------------
    |
    | Where the app sends recorded events (newline-delimited JSON over TCP) and
    | where `php artisan app-watch:agent` listens. The daemon writes a batch to
    | the telemetry database once `flush_events` events are buffered or the
    | oldest has waited `flush_seconds`. Batches that still fail after three attempts, or that
    | would push past `max_buffered_events` in memory, are written as gzip files
    | to `spool_path` (at most `spool_max_bytes`, the oldest deleted first) and
    | replayed oldest first once the database accepts writes again.
    |
    */

    'daemon' => [
        'host' => env('APP_WATCH_DAEMON_HOST', '127.0.0.1'),
        'port' => (int) env('APP_WATCH_DAEMON_PORT', 2407),
        'flush_events' => (int) env('APP_WATCH_DAEMON_FLUSH_EVENTS', 500),
        'flush_seconds' => (int) env('APP_WATCH_DAEMON_FLUSH_SECONDS', 5),
        'max_buffered_events' => (int) env('APP_WATCH_DAEMON_MAX_BUFFERED_EVENTS', 10000),
        'spool_path' => env('APP_WATCH_DAEMON_SPOOL_PATH', storage_path('app/app-watch/spool')),
        'spool_max_bytes' => (int) env('APP_WATCH_DAEMON_SPOOL_MAX_BYTES', 100 * 1024 * 1024),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sample Rates
    |--------------------------------------------------------------------------
    |
    | Fraction (0–1) of events recorded per type. Request and job rates are
    | decided once per trace, so a sampled trace is recorded completely and an
    | unsampled one not at all. Exceptions are always recorded (1.0).
    |
    */

    'sample_rates' => [
        'request' => 1.0,
        'query' => 1.0,
        'job' => 1.0,
        'outgoing_request' => 1.0,
        'cache' => 1.0,
        'log' => 1.0,
        'exception' => 1.0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Ignore Rules
    |--------------------------------------------------------------------------
    |
    | Request paths (globs), job class names (globs) and SQL regexes that are
    | never recorded. Queries on the agent's telemetry connection (unless it is
    | also the app's default connection) are never recorded either.
    |
    | `commands` are Artisan command names (globs) that get no trace and no
    | command record. The defaults skip the agent's own `app-watch:*` upkeep
    | and the long-running workers (queue workers, the scheduler, Horizon and
    | Octane), whose work is traced per job or request instead. Commands the
    | scheduler starts are recorded; only `schedule:*` itself is skipped.
    |
    */

    'ignore' => [
        'paths' => ['/up', '/telescope*', '/horizon*'],
        'jobs' => [],
        'queries' => [],
        'commands' => ['app-watch:*', 'queue:work', 'queue:listen', 'schedule:*', 'horizon*', 'octane:*'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Level
    |--------------------------------------------------------------------------
    |
    | The minimum PSR-3 level of log messages that are recorded.
    |
    */

    'log_level' => env('APP_WATCH_LOG_LEVEL', 'warning'),

    /*
    |--------------------------------------------------------------------------
    | Deploy
    |--------------------------------------------------------------------------
    |
    | An optional deploy identifier (release tag or commit) attached to every
    | event.
    |
    */

    'deploy' => env('APP_WATCH_DEPLOY'),

];
