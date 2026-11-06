# App Watch Agent

Records telemetry inside a monitored Laravel app and writes it straight into the app's own telemetry database (a Postgres database the client owns). App Watch connects to that database to read it for the dashboards; there is no ingest API.

## Architecture

```
app ──NDJSON over TCP──▶ daemon (php artisan app-watch:agent) ──SQL──▶ Postgres (client DB) ◀──read── App Watch
```

```
single server:    App Watch ──read──> Client DB <──write── Client app + agent daemon
multiple servers: App Watch ──read──> Client DB <──write── agent daemon <── app servers
```

The app hands each finished trace to a daemon on the same host, which batches events and writes them to the telemetry database. A missing or stuck daemon costs a request at most ~250 ms (50 ms to connect, 200 ms to write); the events are then dropped and the app carries on. With several app servers, run a daemon on every host (or point `APP_WATCH_DAEMON_HOST` at a shared one); all of them write to the same client database.

The agent owns the telemetry database: its schema (`app-watch:install`), the daily partitions and the rollups (both run by the app's scheduler). App Watch only reads it; issue triage (resolving, ignoring, assigning) lives in App Watch's own database.

## Requirements

- PHP 8.3+
- Laravel 13
- Postgres 16+ (telemetry writes go to Postgres only)
- `pcntl` extension (recommended) so the daemon flushes buffered events on SIGTERM/SIGINT
- Multi-server apps: a cache store with atomic locks shared by all servers (`redis`, `database`, `memcached`, `dynamodb`, or `file` on a shared disk), so `onOneServer()` runs the upkeep once (see [Scheduling](#scheduling))

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

## Setup

1. In App Watch, open **Settings → Environments** and expand **Agent setup** on the environment this app reports to. It shows the environment id and a copyable `.env` snippet (the database password is never filled in).

2. Add the environment id and the telemetry database to the monitored app's `.env`:

    ```env
    APP_WATCH_ENVIRONMENT_ID=123
    APP_WATCH_DB_HOST=telemetry-db.example.com
    APP_WATCH_DB_PORT=5432
    APP_WATCH_DB_DATABASE=app_watch
    APP_WATCH_DB_USERNAME=app_watch_agent
    APP_WATCH_DB_PASSWORD=your-password
    APP_WATCH_DB_SSLMODE=require
    ```

    To write telemetry into a connection the app already defines (e.g. its own `pgsql` database), set `APP_WATCH_DB_CONNECTION=pgsql` instead; the `APP_WATCH_DB_*` host settings are then ignored. Retention (`APP_WATCH_RETENTION_DAYS`, `APP_WATCH_PARTITIONS_AHEAD_DAYS`) and rollup keys (`APP_WATCH_ROLLUPS_*`) are optional; see [Environment variables](#environment-variables).

    The agent records nothing while `APP_WATCH_ENABLED=false`, while `APP_WATCH_ENVIRONMENT_ID` is not a positive integer, or while its connection (`APP_WATCH_DB_CONNECTION`, default `app_watch`) is missing. The `app_watch` connection is registered from the `APP_WATCH_DB_*` variables unless the app already defines one with that name.

3. Install the telemetry schema and today's partitions:

    ```bash
    php artisan app-watch:install [--database=app_watch]
    ```

    Run it on first setup and again after every agent upgrade: it applies new migrations only and prints the installed schema version. A second run changes nothing. App Watch shows a "telemetry unavailable" page for a database whose schema is older than it supports.

4. Start the daemon on every host that serves requests or runs queue workers (see [Running the daemon](#running-the-daemon)).

5. Make sure the app's scheduler runs (see [Scheduler requirement](#scheduler-requirement)).

6. In App Watch, save the database under **Settings → Databases** using the [read-only role](#app-watchs-read-only-role) and assign it to the project under **Settings → Projects**.

Optionally publish the config to change sample rates or ignore rules:

```bash
php artisan vendor:publish --tag=app-watch-config
```

## Scheduler requirement

The agent does its upkeep through the app's scheduler, so the app **must** run `php artisan schedule:run` every minute (cron) or `php artisan schedule:work`. Without it there are no rollups, so the dashboards' charts stay empty, and no new partitions: inserts start failing (SQLSTATE 23514) once the `APP_WATCH_PARTITIONS_AHEAD_DAYS` days of partitions created by `app-watch:install` run out.

```cron
* * * * * cd /var/www/app && php artisan schedule:run >> /dev/null 2>&1
```

## Environment variables

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
| `APP_WATCH_DAEMON_FLUSH_EVENTS`           | `500`                         | Write a batch once this many events are buffered                                            |
| `APP_WATCH_DAEMON_FLUSH_SECONDS`          | `5`                           | ...or once the oldest buffered event is this old                                            |
| `APP_WATCH_DAEMON_MAX_BUFFERED_EVENTS`    | `10000`                       | In-memory cap before batches spill to the spool                                             |
| `APP_WATCH_DAEMON_SPOOL_PATH`             | `storage/app/app-watch/spool` | Where undeliverable batches are kept                                                        |
| `APP_WATCH_DAEMON_SPOOL_MAX_BYTES`        | `104857600` (100 MB)          | Spool size cap; the oldest batches are deleted first                                        |

## Sample rates and ignore rules

In the published `config/app-watch.php`:

- `sample_rates` — fraction (0–1) recorded per event type. Request and job rates are decided once per trace, so a trace is recorded completely or not at all. Exceptions are always recorded.
- `ignore.paths` — request path globs (default `/up`, `/telescope*`, `/horizon*`).
- `ignore.jobs` — job class name globs.
- `ignore.queries` — SQL regexes, e.g. `'/^select \* from "sessions"/'`.
- `ignore.commands` — Artisan command name globs that get no trace and no command record (default `app-watch:*`, `queue:work`, `queue:listen`, `schedule:*`, `horizon*`, `octane:*`). Commands the scheduler starts are recorded; only `schedule:*` itself is skipped.

The agent's own commands (`app-watch:*`, unless removed from `ignore.commands`) and every query on its telemetry connection (`APP_WATCH_DB_CONNECTION`) are never recorded, unless that connection is also the app's default one (e.g. `APP_WATCH_DB_CONNECTION=pgsql`), whose app queries are still recorded. An app command that queries the dedicated `app_watch` connection itself is therefore not recorded on that connection either.

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

Run it daily. It drops the raw telemetry partitions (`{table}_pYYYYMMDD`, UTC days) older than `APP_WATCH_RETENTION_DAYS` (default 14, must be at least 1), then creates today … today + `APP_WATCH_PARTITIONS_AHEAD_DAYS` (default 7), and prints `Created N partition(s), dropped M partition(s).` A second run the same day changes nothing. Only children of the tables in `app-watch.tables` (a fixed whitelist: `requests`, `queries`, `job_runs`, `outgoing_requests`, `cache_events`, `logs`, `exception_occurrences`, `command_runs`) are ever touched. Every partition is created or dropped by its own statement without a wrapping transaction, so the connection must not go through a transaction-mode pooler (see [Connection poolers](#connection-poolers)).

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

| Command                    | Runs           |
| -------------------------- | -------------- |
| `app-watch:rollups minute` | every minute   |
| `app-watch:rollups hour`   | hourly at :02  |
| `app-watch:rollups prune`  | daily at 00:15 |
| `app-watch:partitions`     | daily at 00:05 |

Every entry runs `withoutOverlapping()` and `onOneServer()`. `onOneServer()` needs a cache store with atomic locks shared by all servers (`redis`, `database`, `memcached`, `dynamodb`, or `file` on a shared disk) as the scheduler's store (`cache.schedule_store`, else the default store). With the `array` or `null` store the agent logs a warning and schedules the entries without `onOneServer()`, which is fine for a single server; multi-server apps must use a lock-capable cache store or every server runs the upkeep. An unconfigured or disabled agent schedules nothing. `php artisan schedule:list` shows the entries. These commands get no trace, so their rollup, prune and partition SQL never shows up as the app's own telemetry.

## Sharing one database between projects

Several projects (and all their environments) can write to the same database. Every row carries its environment id, which is globally unique, and App Watch filters every query by it, so their data never mixes. There is nothing else to configure: give each app its own `APP_WATCH_ENVIRONMENT_ID` and the same `APP_WATCH_DB_*` settings, and assign the same saved database to each project in App Watch. Any of the apps may run `app-watch:install`; the upkeep is idempotent, so several schedulers running it against one database is harmless.

## App Watch's read-only role

App Watch should not connect with the agent's role. Create a separate, SELECT-only role and use it for the connection you save under **Settings → Databases**. Run this once as the agent's role (the owner of the telemetry tables), after `app-watch:install`, replacing the database name, the password and `app_watch_agent` (the role the agent connects as):

```sql
CREATE ROLE app_watch_reader LOGIN PASSWORD 'change-me';

GRANT CONNECT ON DATABASE app_watch TO app_watch_reader;
GRANT USAGE ON SCHEMA public TO app_watch_reader;

-- Partitioned tables need no per-partition grant: Postgres checks privileges on the parent.
GRANT SELECT ON ALL TABLES IN SCHEMA public TO app_watch_reader;

-- Tables that future agent upgrades create stay readable.
ALTER DEFAULT PRIVILEGES FOR ROLE app_watch_agent IN SCHEMA public
    GRANT SELECT ON TABLES TO app_watch_reader;
```

The reader can then `SELECT` from every telemetry table, while any `INSERT`, `UPDATE` or `DELETE` fails with `permission denied`. App Watch never writes to the telemetry database: issue triage (resolving, ignoring, assigning) lives in App Watch's own database.

## Static egress IP

App Watch connects to client databases from one fixed outbound IP address. When it is configured (`TELEMETRY_EGRESS_IP` on the App Watch server), it is shown on **Settings → Databases** and in the environment's **Agent setup** panel. Add it to your managed Postgres firewall or allowlist (AWS security group, Supabase network restrictions, DigitalOcean trusted sources, …) so App Watch can reach the database; nothing else needs to be opened.

## Connection poolers

Transaction-mode poolers (PgBouncer in `pool_mode = transaction`, Supabase's pooler on port 6543) break the agent's partition DDL in `app-watch:install` and `app-watch:partitions`. Point the agent (`APP_WATCH_DB_*` or `APP_WATCH_DB_CONNECTION`) at a direct connection or a session-mode pooler (Supabase port 5432). App Watch's read-only role only runs plain queries, so its saved connection may use a pooler.

## Times are UTC

The agent writes `recorded_at` as UTC `timestamptz` values, partitions are UTC days (`{table}_pYYYYMMDD`) and the rollup buckets are UTC minutes and hours; App Watch reads them as UTC too. Keep it that way: don't change the client database's or the roles' `timezone` setting to shift `recorded_at` (if in doubt, `ALTER DATABASE app_watch SET timezone TO 'UTC'`).

## What is recorded

No code changes are needed. Each request, queued job run and console command is one trace; its events are flushed to the daemon when it ends.

| Type              | Source                                                                               | Table                                                   |
| ----------------- | ------------------------------------------------------------------------------------ | ------------------------------------------------------- |
| Requests          | `RequestHandled`                                                                     | `requests`                                              |
| Queries           | `QueryExecuted` (SQL normalized)                                                     | `queries`                                               |
| Jobs              | `JobProcessing` / `JobProcessed` / `JobFailed` / releases                            | `job_runs`                                              |
| Commands          | `CommandStarting` / `CommandFinished`: name, exit code, duration, redacted arguments | `command_runs`                                          |
| Outgoing requests | HTTP client `RequestSending` / `ResponseReceived` / failures                         | `outgoing_requests`                                     |
| Cache             | Hits, misses, writes, forgets                                                        | `cache_events`                                          |
| Logs              | `MessageLogged` at or above `APP_WATCH_LOG_LEVEL`                                    | `logs`                                                  |
| Exceptions        | The exception handler's reportable callbacks                                         | `exception_occurrences` (grouped in `exception_groups`) |

Every table except `exception_groups` is partitioned per UTC day and expires through [partition upkeep](#partition-upkeep). Requests, queries, jobs, commands, outgoing requests and cache events are also rolled up per minute and hour (commands per command name, failed runs counted as errors).

Command arguments and options keep their names only: every value is stored as `?` (a set flag as `true`), so passwords, tokens or emails passed on the command line are never recorded. A command called from inside another command (`Artisan::call()`) is recorded as its own command event on the outer command's trace. Commands matching `ignore.commands` (by default `app-watch:*`, `queue:work`, `queue:listen`, `schedule:*`, `horizon*`, `octane:*`) get no trace and no record.

## Troubleshooting

The daemon writes problems to STDERR (the Supervisor log above):

| Message                                                                              | Meaning and fix                                                                                                                                              |
| ------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `App Watch is disabled (APP_WATCH_ENABLED=false)…`                                   | The daemon exits with 1. Set `APP_WATCH_ENABLED=true`.                                                                                                       |
| `APP_WATCH_ENVIRONMENT_ID is not set…`                                               | Unconfigured: the daemon exits with 1. Copy the id from Settings → Environments → Agent setup.                                                               |
| `The telemetry database connection "app_watch" is not configured…`                   | Unconfigured: set the `APP_WATCH_DB_*` variables or point `APP_WATCH_DB_CONNECTION` at an existing connection.                                               |
| `Could not write N events to the database (…); retrying at …`                        | The database is unreachable, timed out or deadlocked. The batch is retried with backoff; check the host, firewall and credentials.                           |
| `Spooled N events to … until the database can take them.`                            | Three attempts failed, so the batch went to the spool. It is replayed oldest first once writes succeed again.                                                |
| `Dropped N spooled events: the spool reached its size limit…`                        | The database has been down long enough to fill `APP_WATCH_DAEMON_SPOOL_MAX_BYTES`; the oldest batches were deleted.                                          |
| `N events were rejected by the database (SQLSTATE …); they were dropped.`            | Poison: SQLSTATE class 22 (bad data) or 23 (constraint violation). The chunk is not retried. Usually a schema mismatch: run `php artisan app-watch:install`. |
| `No partition exists for the events' recorded_at day: the scheduler is not running…` | SQLSTATE 23514: partitions are missing. Run `php artisan app-watch:partitions` now and fix the [scheduler](#scheduler-requirement).                          |
| `The pcntl extension is not available…`                                              | The daemon runs, but events buffered when it is stopped are lost. Install `pcntl`.                                                                           |

## Testing

The suite runs on Postgres (see `phpunit.xml`): it needs a local server on `127.0.0.1:5432` with a `root` user and an empty `app_watch_agent_testing` database.

```bash
createdb -h 127.0.0.1 -U root app_watch_agent_testing
cd packages/app-watch-agent
composer install
vendor/bin/pest
```
