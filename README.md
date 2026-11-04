# App Watch Agent

Records telemetry inside a monitored Laravel app and writes it to the app's telemetry database, which the App Watch monitor reads.

```
your app ──NDJSON over TCP──▶ local daemon (php artisan app-watch:agent) ──SQL──▶ telemetry database (Postgres)
```

The app hands each finished trace to a daemon on the same host, which batches events and writes them to the telemetry database. A missing or stuck daemon costs a request at most ~250 ms (50 ms to connect, 200 ms to write); the events are then dropped and the app carries on.

## Requirements

- PHP 8.3+
- Laravel 13
- Postgres 16+ (telemetry writes go to Postgres only)
- `pcntl` extension (recommended) so the daemon flushes buffered events on SIGTERM/SIGINT

## Installation

The package is not on Packagist. Add it as a path (or VCS) repository in the monitored app's `composer.json`:

```json
"repositories": [
    {
        "type": "path",
        "url": "../app-watch/packages/app-watch-agent",
        "options": { "symlink": true }
    }
],
"require": {
    "app-watch/agent": "@dev"
}
```

```bash
composer update app-watch/agent
```

The service provider is auto-discovered.

## Configuration

1. In the monitor, look up the environment's id on the Settings → Environments page.

2. Add the environment id and the telemetry database to the monitored app's `.env`:

    ```env
    APP_WATCH_ENVIRONMENT_ID=123
    APP_WATCH_DB_HOST=telemetry-db.example.com
    APP_WATCH_DB_DATABASE=app_watch
    APP_WATCH_DB_USERNAME=app_watch_agent
    APP_WATCH_DB_PASSWORD=your-password
    ```

    The agent records nothing while `APP_WATCH_ENABLED=false`, while `APP_WATCH_ENVIRONMENT_ID` is not a positive integer, or while its connection (`APP_WATCH_DB_CONNECTION`, default `app_watch`) is missing. The `app_watch` connection is registered from the `APP_WATCH_DB_*` variables unless the app already defines one with that name.

3. Optionally publish the config to change sample rates or ignore rules:

    ```bash
    php artisan vendor:publish --tag=app-watch-config
    ```

### Environment variables

| Variable                                  | Default                       | Purpose                                                                                     |
| ----------------------------------------- | ----------------------------- | ------------------------------------------------------------------------------------------- |
| `APP_WATCH_ENABLED`                       | `true`                        | Master switch                                                                               |
| `APP_WATCH_ENVIRONMENT_ID`                | —                             | Environment id from the monitor; the agent stays silent until it is a positive integer      |
| `APP_WATCH_DB_CONNECTION`                 | `app_watch`                   | Laravel connection telemetry is written to; name an existing one (e.g. `pgsql`) to reuse it |
| `APP_WATCH_DB_HOST`                       | —                             | Telemetry Postgres host (used when the agent registers the connection)                      |
| `APP_WATCH_DB_PORT`                       | `5432`                        | Telemetry Postgres port                                                                     |
| `APP_WATCH_DB_DATABASE`                   | —                             | Telemetry database name; the connection is only registered when set                         |
| `APP_WATCH_DB_USERNAME`                   | —                             | Telemetry database user                                                                     |
| `APP_WATCH_DB_PASSWORD`                   | —                             | Telemetry database password                                                                 |
| `APP_WATCH_DB_SSLMODE`                    | `require`                     | Postgres `sslmode`                                                                          |
| `APP_WATCH_RETENTION_DAYS`                | `14`                          | Days of raw telemetry kept (daily partitions)                                               |
| `APP_WATCH_PARTITIONS_AHEAD_DAYS`         | `7`                           | Future UTC days that get a partition                                                        |
| `APP_WATCH_ROLLUPS_GRACE_MINUTES`         | `2`                           | Closed minutes the minute rollup re-aggregates                                              |
| `APP_WATCH_ROLLUPS_MINUTE_RETENTION_DAYS` | `7`                           | Days of minute rollups kept                                                                 |
| `APP_WATCH_ROLLUPS_HOUR_RETENTION_DAYS`   | `90`                          | Days of hour rollups kept                                                                   |
| `APP_WATCH_DEPLOY`                        | —                             | Release tag or commit attached to every event                                               |
| `APP_WATCH_LOG_LEVEL`                     | `warning`                     | Minimum PSR-3 level of log messages recorded                                                |
| `APP_WATCH_DAEMON_HOST`                   | `127.0.0.1`                   | Daemon listen/connect host                                                                  |
| `APP_WATCH_DAEMON_PORT`                   | `2407`                        | Daemon listen/connect port                                                                  |
| `APP_WATCH_DAEMON_FLUSH_EVENTS`           | `500`                         | Post a batch once this many events are buffered                                             |
| `APP_WATCH_DAEMON_FLUSH_SECONDS`          | `5`                           | ...or once the oldest buffered event is this old                                            |
| `APP_WATCH_DAEMON_MAX_BUFFERED_EVENTS`    | `10000`                       | In-memory cap before batches spill to the spool                                             |
| `APP_WATCH_DAEMON_SPOOL_PATH`             | `storage/app/app-watch/spool` | Where undeliverable batches are kept                                                        |
| `APP_WATCH_DAEMON_SPOOL_MAX_BYTES`        | `104857600` (100 MB)          | Spool size cap; the oldest batches are deleted first                                        |

### Sample rates and ignore rules

In the published `config/app-watch.php`:

- `sample_rates` — fraction (0–1) recorded per event type. Request and job rates are decided once per trace, so a trace is recorded completely or not at all. Exceptions are always recorded.
- `ignore.paths` — request path globs (default `/up`, `/telescope*`, `/horizon*`).
- `ignore.jobs` — job class name globs.
- `ignore.queries` — SQL regexes, e.g. `'/^select \* from "sessions"/'`.

## Running the daemon

```bash
php artisan app-watch:agent
```

Run it on every host that serves requests or runs queue workers, kept alive by Supervisor or systemd like `queue:work`:

```ini
[program:app-watch-agent]
command=php /var/www/app/artisan app-watch:agent
autostart=true
autorestart=true
stopsignal=TERM
stopwaitsecs=30
redirect_stderr=true
stdout_logfile=/var/www/app/storage/logs/app-watch-agent.log
```

Delivery behaviour:

- Batches are written in chunks of at most 1000 events, each in one transaction with a statement timeout (10 s, 3 s for the final write on shutdown).
- A connection error, timeout, deadlock or any other unexpected error is retried with backoff (1 s … 60 s); after three attempts the batch is written as a gzip file to the spool and replayed oldest first once the database accepts writes again.
- A chunk the database rejects with an SQLSTATE of class 22 (bad data) or 23 (constraint violation) is poison: it is dropped (a spooled one is deleted), counted and reported once on STDERR with its SQLSTATE until a write succeeds. SQLSTATE 23514 means no partition exists for the events' day: make sure the scheduler runs `app-watch:partitions`.
- On SIGTERM/SIGINT the daemon stops accepting, makes one final delivery attempt, spools the rest and exits 0. Without `pcntl` it cannot catch the signal, so buffered events are lost when it stops.

## Partition upkeep

```bash
php artisan app-watch:partitions [--database=app_watch]
```

Run it daily. It drops the raw telemetry partitions (`{table}_pYYYYMMDD`, UTC days) older than `APP_WATCH_RETENTION_DAYS` (default 14, must be at least 1), then creates today … today + `APP_WATCH_PARTITIONS_AHEAD_DAYS` (default 7), and prints `Created N partition(s), dropped M partition(s).` A second run the same day changes nothing. Only children of the tables in `app-watch.tables` (a fixed whitelist) are ever touched. Every partition is created or dropped by its own statement without a wrapping transaction; use a direct or session-mode connection, not a transaction-mode pooler (PgBouncer in transaction mode, Supabase port 6543).

## Rollup upkeep

```bash
php artisan app-watch:rollups minute [--database=app_watch]
php artisan app-watch:rollups hour   [--database=app_watch]
php artisan app-watch:rollups prune  [--database=app_watch]
```

The dashboards chart from per-minute and per-hour rollups the agent builds in the telemetry database. They are plain commands, not queued jobs, so no queue worker is needed:

- `minute` — run every minute. Re-aggregates the last `APP_WATCH_ROLLUPS_GRACE_MINUTES` closed minutes (at least 1) into `rollups_minute`, so events arriving late are still counted. The open minute is never aggregated.
- `hour` — run at `hourlyAt(2)`. Aggregates the last closed hour from the raw rows (percentiles cannot be merged from minutes) into `rollups_hour`; within the grace window after the hour boundary it also re-aggregates the hour before.
- `prune` — run daily. Deletes minute rollups older than `APP_WATCH_ROLLUPS_MINUTE_RETENTION_DAYS` and hour rollups older than `APP_WATCH_ROLLUPS_HOUR_RETENTION_DAYS` (a bucket exactly at the cutoff is kept), in chunks of 10 000 rows.

Rows are grouped per environment, type, key (truncated to 512 characters) and bucket, and upserted, so every run is idempotent and several projects can share one database. The command prints the number of buckets upserted or rows pruned; an unknown kind or `--database` connection exits with 1.

## Scheduling

Once the agent is configured (enabled, `APP_WATCH_ENVIRONMENT_ID` set and a telemetry connection), it registers its own upkeep in the app's scheduler, so all the app needs is `php artisan schedule:run` every minute (or `php artisan schedule:work`):

| Command                    | Runs                |
| -------------------------- | ------------------- |
| `app-watch:rollups minute` | every minute        |
| `app-watch:rollups hour`   | hourly at :02       |
| `app-watch:rollups prune`  | daily at 00:15      |
| `app-watch:partitions`     | daily at 00:05      |

Every entry runs `withoutOverlapping()` and `onOneServer()`. `onOneServer()` needs a cache store with atomic locks shared by all servers (`redis`, `database`, `memcached`, `dynamodb`, or `file` on a shared disk) as the scheduler's store (`cache.schedule_store`, else the default store). With the `array` or `null` store the agent logs a warning and schedules the entries without `onOneServer()`, which is fine for a single server; multi-server apps must use a lock-capable cache store or every server runs the upkeep. An unconfigured or disabled agent schedules nothing. `php artisan schedule:list` shows the entries.

## What is recorded

No code changes are needed. Each request, queued job run and console command is one trace; its events are flushed to the daemon when it ends.

| Type              | Source                                                       |
| ----------------- | ------------------------------------------------------------ |
| Requests          | `RequestHandled`                                             |
| Queries           | `QueryExecuted` (SQL normalized)                             |
| Jobs              | `JobProcessing` / `JobProcessed` / `JobFailed` / releases    |
| Outgoing requests | HTTP client `RequestSending` / `ResponseReceived` / failures |
| Cache             | Hits, misses, writes, forgets                                |
| Logs              | `MessageLogged` at or above `APP_WATCH_LOG_LEVEL`            |
| Exceptions        | The exception handler's reportable callbacks                 |

## Testing

The suite runs on Postgres (see `phpunit.xml`): it needs a local server on `127.0.0.1:5432` with a `root` user and an empty `app_watch_agent_testing` database.

```bash
createdb -h 127.0.0.1 -U root app_watch_agent_testing
cd packages/app-watch-agent
composer install
vendor/bin/pest
```
