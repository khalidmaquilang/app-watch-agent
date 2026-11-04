<?php

declare(strict_types=1);

use AppWatch\Agent\Daemon\Batcher;
use AppWatch\Agent\Daemon\Daemon;
use AppWatch\Agent\Daemon\Outbox;
use AppWatch\Agent\Daemon\Spool;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    prepareTelemetryDatabase();
    config(['app-watch.daemon.port' => unusedPort()]);
});

afterEach(function (): void {
    restoreTelemetryConnection();
    dropTelemetrySchema();
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});

/**
 * Make every insert into `requests` from a path starting with "/poison" fail with the given
 * SQLSTATE, and let every other row through.
 */
function poisonRequestPaths(string $sqlstate): void
{
    DB::connection('app_watch')->unprepared(<<<SQL
        CREATE OR REPLACE FUNCTION app_watch_test_fail_insert() RETURNS trigger AS \$\$
        BEGIN
            IF NEW.path LIKE '/poison%' THEN
                RAISE EXCEPTION 'bad row' USING ERRCODE = '{$sqlstate}';
            END IF;
            RETURN NEW;
        END
        \$\$ LANGUAGE plpgsql;
        CREATE TRIGGER app_watch_test_fail_insert BEFORE INSERT ON requests FOR EACH ROW EXECUTE FUNCTION app_watch_test_fail_insert();
        SQL);
}

/**
 * The given number of request events whose path starts with "/poison".
 *
 * @return array<int, array<string, mixed>>
 */
function poisonEvents(int $count): array
{
    return array_map(static fn (array $event): array => [...$event, 'path' => '/poison'.$event['path']], daemonEvents($count));
}

it('refuses to start without an environment id', function (mixed $environment_id): void {
    config(['app-watch.environment_id' => $environment_id]);

    $this->artisan('app-watch:agent', ['--max-ticks' => 1])
        ->expectsOutputToContain('APP_WATCH_ENVIRONMENT_ID is not set')
        ->assertExitCode(1);
})->with([null, '', 0]);

it('refuses to start without the telemetry connection', function (): void {
    $connection = config('database.connections.app_watch');
    config(['database.connections.app_watch' => null]);

    try {
        $this->artisan('app-watch:agent', ['--max-ticks' => 1])
            ->expectsOutputToContain('The telemetry database connection "app_watch" is not configured. Set APP_WATCH_DB_HOST')
            ->assertExitCode(1);
    } finally {
        config(['database.connections.app_watch' => $connection]);
    }
});

it('refuses to start while disabled', function (): void {
    config(['app-watch.enabled' => false]);

    $this->artisan('app-watch:agent', ['--max-ticks' => 1])
        ->expectsOutputToContain('App Watch is disabled (APP_WATCH_ENABLED=false)')
        ->assertExitCode(1);
});

it('exits 1 with a message when the port is in use', function (): void {
    [$server, $port] = startTestDaemon();
    config(['app-watch.daemon.port' => $port]);

    try {
        $this->artisan('app-watch:agent', ['--max-ticks' => 1])
            ->expectsOutputToContain("Could not listen on tcp://127.0.0.1:{$port}")
            ->assertExitCode(1);
    } finally {
        fclose($server);
    }
});

it('listens, runs the given number of ticks and stops', function (): void {
    $port = config('app-watch.daemon.port');

    $this->artisan('app-watch:agent', ['--max-ticks' => 2])
        ->expectsOutputToContain("App Watch agent listening on tcp://127.0.0.1:{$port}")
        ->assertExitCode(0);

    // The socket is closed again, so the port can be bound.
    $daemon = app(Daemon::class);
    $daemon->listen('127.0.0.1', $port);
    $daemon->close();
});

it('starts and listens while the database is down', function (): void {
    breakTelemetryConnection();
    $port = config('app-watch.daemon.port');

    $this->artisan('app-watch:agent', ['--max-ticks' => 1])
        ->expectsOutputToContain("App Watch agent listening on tcp://127.0.0.1:{$port}")
        ->assertExitCode(0);
});

it('reports a rejection SQLSTATE once until a batch is written again', function (): void {
    poisonRequestPaths('22P02');
    $batcher = app(Batcher::class);
    $outbox = app(Outbox::class);
    app()->instance(Batcher::class, $batcher);
    app()->instance(Outbox::class, $outbox);
    config(['app-watch.daemon.flush_events' => 1]);
    $outbox->push(poisonEvents(5));

    // Every write makes new events arrive, so every tick writes a batch: 5 poison, 1 poison, 2 stored, 3 poison.
    $calls = 0;
    DB::connection('app_watch')->beforeStartingTransaction(function ($connection) use ($batcher, &$calls): void {
        if ($connection->transactionLevel() !== 0) {
            return;
        }

        $calls++;
        $next = match ($calls) {
            1 => poisonEvents(1),
            2 => daemonEvents(2),
            3 => poisonEvents(3),
            default => [],
        };

        foreach ($next as $event) {
            $batcher->add($event);
        }
    });

    $this->artisan('app-watch:agent', ['--max-ticks' => 4])
        ->expectsOutputToContain('5 events were rejected by the database (SQLSTATE 22P02); they were dropped.')
        ->doesntExpectOutputToContain('1 events were rejected')
        ->expectsOutputToContain('3 events were rejected by the database (SQLSTATE 22P02); they were dropped.')
        ->doesntExpectOutputToContain('HTTP')
        ->assertExitCode(0);

    expect($calls)->toBe(4)
        ->and(storedRequestPaths())->toBe(['/orders/1', '/orders/2']);
});

it('tells the operator the partition upkeep is not running when a partition is missing', function (): void {
    DB::connection('app_watch')->statement('DROP TABLE requests_p20261103');
    $outbox = app(Outbox::class);
    app()->instance(Outbox::class, $outbox);
    $outbox->push(daemonEvents(2));

    $this->artisan('app-watch:agent', ['--max-ticks' => 1])
        ->expectsOutputToContain('2 events were rejected by the database (SQLSTATE 23514); they were dropped.')
        ->expectsOutputToContain('No partition exists for the events\' recorded_at day: the scheduler is not running `php artisan app-watch:partitions`.')
        ->assertExitCode(0);
});

it('reports a write that will be retried without leaking credentials', function (): void {
    config(['database.connections.app_watch.password' => 'super-secret-password']);
    breakTelemetryConnection();
    $outbox = app(Outbox::class);
    app()->instance(Outbox::class, $outbox);
    $outbox->push(daemonEvents(3));

    $this->artisan('app-watch:agent', ['--max-ticks' => 1])
        ->expectsOutputToContain('Could not write 3 events to the database (SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 1 failed: Connection refused); retrying at')
        ->doesntExpectOutputToContain('super-secret-password')
        ->assertExitCode(0);
});

it('reports events spooled from a full buffer', function (): void {
    config(['app-watch.daemon.max_buffered_events' => 1]);
    breakTelemetryConnection();
    $outbox = app(Outbox::class);
    app()->instance(Outbox::class, $outbox);
    $outbox->push(daemonEvents(3));

    $this->artisan('app-watch:agent', ['--max-ticks' => 1])
        ->expectsOutputToContain("Spooled 3 events to {$this->spool_path} until the database can take them.")
        ->assertExitCode(0);
});

it('reports events dropped because the spool cannot take them', function (): void {
    $file = $this->spool_path.'-file';
    file_put_contents($file, 'not a directory');
    config(['app-watch.daemon.max_buffered_events' => 1, 'app-watch.daemon.spool_path' => $file.'/spool']);
    $outbox = app(Outbox::class);
    app()->instance(Outbox::class, $outbox);
    $outbox->push(daemonEvents(3));

    try {
        $this->artisan('app-watch:agent', ['--max-ticks' => 1])
            ->expectsOutputToContain("Dropped 3 events: they could not be spooled (the spool directory {$file}/spool is not writable).")
            ->assertExitCode(0);
    } finally {
        unlink($file);
    }
});

it('reports a spooled batch deleted as poison', function (): void {
    poisonRequestPaths('23502');
    app(Spool::class)->write(poisonEvents(4));
    $file = basename(app(Spool::class)->files()[0]);

    $this->artisan('app-watch:agent', ['--max-ticks' => 1])
        ->expectsOutputToContain("Deleted spooled batch {$file}: 4 of its events were rejected by the database (SQLSTATE 23502).")
        ->assertExitCode(0);

    expect(app(Spool::class)->files())->toBe([]);
});

it('reports corrupt spool files and spooled events deleted for the size limit', function (): void {
    breakTelemetryConnection();
    mkdir($this->spool_path, 0o755, true);
    file_put_contents($this->spool_path.'/00000000000000000001-corrupt.json.gz', 'not gzip');
    app(Spool::class)->write(daemonEvents(5));
    config(['app-watch.daemon.max_buffered_events' => 1, 'app-watch.daemon.spool_max_bytes' => filesize(app(Spool::class)->files()[1]) + 10]);
    $outbox = app(Outbox::class);
    app()->instance(Outbox::class, $outbox);
    $outbox->push(daemonEvents(5));

    $this->artisan('app-watch:agent', ['--max-ticks' => 1])
        ->expectsOutputToContain('Deleted 1 corrupt spool files.')
        ->expectsOutputToContain('Dropped 5 spooled events: the spool reached its size limit')
        ->assertExitCode(0);
});
