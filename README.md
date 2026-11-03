# App Watch Agent

Records telemetry inside a monitored Laravel app and ships it to the App Watch monitor.

```
your app ──NDJSON over TCP──▶ local daemon (php artisan app-watch:agent) ──gzip HTTPS──▶ monitor /api/v1/ingest
```

The app hands each finished trace to a daemon on the same host, which batches events and posts them to the monitor. A missing or stuck daemon costs a request at most ~250 ms (50 ms to connect, 200 ms to write); the events are then dropped and the app carries on.

## Requirements

- PHP 8.3+
- Laravel 13
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

1. In the monitor, open the environment's token page (`/settings/environments/{environment}/tokens`) and issue a token. Only organization Owners and Admins can issue tokens.

2. Add the token and monitor URL to the monitored app's `.env`:

    ```env
    APP_WATCH_TOKEN=your-environment-token
    APP_WATCH_URL=https://monitor.example.com
    ```

    The agent records nothing while `APP_WATCH_TOKEN` is empty or `APP_WATCH_ENABLED=false`.

3. Optionally publish the config to change sample rates or ignore rules:

    ```bash
    php artisan vendor:publish --tag=app-watch-config
    ```

### Environment variables

| Variable                               | Default                       | Purpose                                              |
| -------------------------------------- | ----------------------------- | ---------------------------------------------------- |
| `APP_WATCH_ENABLED`                    | `true`                        | Master switch                                        |
| `APP_WATCH_TOKEN`                      | —                             | Environment token issued by the monitor              |
| `APP_WATCH_URL`                        | —                             | Monitor base URL                                     |
| `APP_WATCH_API_VERSION`                | `v1`                          | Ingest API version                                   |
| `APP_WATCH_DEPLOY`                     | —                             | Release tag or commit attached to every event        |
| `APP_WATCH_LOG_LEVEL`                  | `warning`                     | Minimum PSR-3 level of log messages recorded         |
| `APP_WATCH_DAEMON_HOST`                | `127.0.0.1`                   | Daemon listen/connect host                           |
| `APP_WATCH_DAEMON_PORT`                | `2407`                        | Daemon listen/connect port                           |
| `APP_WATCH_DAEMON_FLUSH_EVENTS`        | `500`                         | Post a batch once this many events are buffered      |
| `APP_WATCH_DAEMON_FLUSH_SECONDS`       | `5`                           | ...or once the oldest buffered event is this old     |
| `APP_WATCH_DAEMON_MAX_BUFFERED_EVENTS` | `10000`                       | In-memory cap before batches spill to the spool      |
| `APP_WATCH_DAEMON_SPOOL_PATH`          | `storage/app/app-watch/spool` | Where undeliverable batches are kept                 |
| `APP_WATCH_DAEMON_SPOOL_MAX_BYTES`     | `104857600` (100 MB)          | Spool size cap; the oldest batches are deleted first |

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

- Batches that fail are retried with backoff; after three attempts they are written as gzip files to the spool and replayed oldest first once the monitor accepts deliveries again.
- A 401/403 (bad token) or other rejection is reported once on STDERR until a delivery succeeds.
- On SIGTERM/SIGINT the daemon stops accepting, makes one final delivery attempt, spools the rest and exits 0. Without `pcntl` it cannot catch the signal, so buffered events are lost when it stops.

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

```bash
cd packages/app-watch-agent
composer install
vendor/bin/pest
```
