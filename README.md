# App Watch Agent

The App Watch agent records what your Laravel app does (requests, queries, jobs, commands, scheduled tasks, outgoing HTTP calls, cache, logs and exceptions) and writes it to a Postgres database you own. App Watch reads that database to show your dashboards and alerts. No code changes are needed in your app, and there is no ingest API.

```
your app ──▶ agent daemon (same host) ──▶ your Postgres telemetry database ◀── App Watch (read-only)
```

If the daemon is down, a request waits at most about 250 ms, the events are dropped and your app carries on.

## Requirements

- PHP 8.3+
- Laravel 13
- Postgres 16+ for the telemetry database
- The `pcntl` extension (recommended), so buffered events are flushed when the daemon stops
- The app's scheduler running every minute
- Several servers: a shared cache store with locks (`redis`, `database`, `memcached`, `dynamodb`) so upkeep runs on one server only

## Installation

The package is not on Packagist yet, so add the GitHub repository first:

```bash
composer config repositories.app-watch-agent vcs https://github.com/khalidmaquilang/app-watch-agent
```

```bash
composer require app-watch/agent:dev-main
```

The service provider is registered automatically.

## Setup

**1. Get your environment id.** In App Watch, open **Settings → Environments** and expand **Agent setup** on the environment this app reports to. It shows the id and a `.env` snippet to copy.

**2. Add it to your `.env`** together with the telemetry database:

```env
APP_WATCH_ENVIRONMENT_ID=123
APP_WATCH_DB_HOST=telemetry-db.example.com
APP_WATCH_DB_PORT=5432
APP_WATCH_DB_DATABASE=app_watch
APP_WATCH_DB_USERNAME=app_watch_agent
APP_WATCH_DB_PASSWORD=your-password
APP_WATCH_DB_SSLMODE=require
```

To use a connection your app already has (for example `pgsql`), set `APP_WATCH_DB_CONNECTION=pgsql` instead of the `APP_WATCH_DB_*` values.

Use a direct connection or a session-mode pooler. Transaction-mode poolers (PgBouncer `pool_mode = transaction`, Supabase port 6543) break the table setup.

**3. Create the telemetry tables:**

```bash
php artisan app-watch:install
```

Run it again after every agent upgrade. It only applies what is new.

**4. Start the daemon** on every server that handles requests or runs queue workers, kept alive like `queue:work`. Supervisor example:

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

**5. Make sure the scheduler runs.** The agent schedules its own upkeep (rollups for the charts and the daily table partitions). Without the scheduler the charts stay empty and, after a week, new events can no longer be saved.

```cron
* * * * * cd /var/www/app && php artisan schedule:run >> /dev/null 2>&1
```

**6. Give App Watch a read-only login.** Run this once on the telemetry database as the agent's user, after step 3. Change the database name, the password and `app_watch_agent` to match yours:

```sql
CREATE ROLE app_watch_reader LOGIN PASSWORD 'change-me';

GRANT CONNECT ON DATABASE app_watch TO app_watch_reader;
GRANT USAGE ON SCHEMA public TO app_watch_reader;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO app_watch_reader;

ALTER DEFAULT PRIVILEGES FOR ROLE app_watch_agent IN SCHEMA public
    GRANT SELECT ON TABLES TO app_watch_reader;
```

**7. Connect it in App Watch.** Save the database under **Settings → Databases** with the `app_watch_reader` login, then assign it to your project under **Settings → Projects**. If your database has a firewall, allow the App Watch IP shown on that page.

Several projects can share one telemetry database. Give each app its own `APP_WATCH_ENVIRONMENT_ID` and the same database settings.

## Configuration

Common `.env` options:

| Variable                   | Default   | What it does                                       |
| -------------------------- | --------- | -------------------------------------------------- |
| `APP_WATCH_ENABLED`        | `true`    | Turn recording on or off                           |
| `APP_WATCH_DEPLOY`         | —         | Release tag or commit attached to every event      |
| `APP_WATCH_LOG_LEVEL`      | `warning` | Lowest log level recorded                          |
| `APP_WATCH_RETENTION_DAYS` | `14`      | Days of raw events kept                            |
| `APP_WATCH_DAEMON_HOST`    | `127.0.0.1` | Where the app finds the daemon                 |
| `APP_WATCH_DAEMON_PORT`    | `2407`    | Daemon port                                        |

To change sample rates or skip paths, jobs, queries or commands, publish the config and edit `config/app-watch.php`:

```bash
php artisan vendor:publish --tag=app-watch-config
```

Command arguments are never stored, only their names, so passwords or tokens passed on the command line stay private.

## Troubleshooting

The daemon reports problems in its log (the Supervisor log above):

- **"APP_WATCH_ENVIRONMENT_ID is not set" / "connection is not configured"**: finish steps 1 and 2.
- **"Could not write N events… retrying"**: the database is unreachable. Check the host, firewall and password. Events are kept on disk and sent once it is back.
- **"No partition exists…" (SQLSTATE 23514)**: the scheduler is not running. Run `php artisan app-watch:partitions` now, then fix step 5.
- **"rejected by the database"**: usually the tables are out of date. Run `php artisan app-watch:install`.
- **App Watch shows "telemetry unavailable"**: run `php artisan app-watch:install` to bring the tables up to date.

All environment variables, the delivery rules and everything the agent records are documented in [AGENTS.md](AGENTS.md).

## License

MIT
