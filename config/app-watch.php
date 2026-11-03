<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Master switch. The agent also stays silent while no token is configured.
    |
    */

    'enabled' => (bool) env('APP_WATCH_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Monitor
    |--------------------------------------------------------------------------
    |
    | The environment token issued by the App Watch monitor, the monitor's base
    | URL and the ingest API version. The agent posts batches to
    | "{monitor_url}/api/{api_version}/ingest".
    |
    */

    'token' => env('APP_WATCH_TOKEN'),

    'monitor_url' => env('APP_WATCH_URL'),

    'api_version' => env('APP_WATCH_API_VERSION', 'v1'),

    /*
    |--------------------------------------------------------------------------
    | Local Daemon
    |--------------------------------------------------------------------------
    |
    | Where the app sends recorded events (newline-delimited JSON over TCP) and
    | where `php artisan app-watch:agent` listens. The daemon posts a batch once
    | `flush_events` events are buffered or the oldest has waited
    | `flush_seconds`, and keeps at most `max_buffered_events` in memory while
    | the monitor is unreachable (the oldest are dropped past that).
    |
    */

    'daemon' => [
        'host' => env('APP_WATCH_DAEMON_HOST', '127.0.0.1'),
        'port' => (int) env('APP_WATCH_DAEMON_PORT', 2407),
        'flush_events' => (int) env('APP_WATCH_DAEMON_FLUSH_EVENTS', 500),
        'flush_seconds' => (int) env('APP_WATCH_DAEMON_FLUSH_SECONDS', 5),
        'max_buffered_events' => (int) env('APP_WATCH_DAEMON_MAX_BUFFERED_EVENTS', 10000),
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
    | never recorded.
    |
    */

    'ignore' => [
        'paths' => ['/up', '/telescope*', '/horizon*'],
        'jobs' => [],
        'queries' => [],
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
