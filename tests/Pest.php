<?php

declare(strict_types=1);

use AppWatch\Agent\Daemon\Spool;
use AppWatch\Agent\Enums\JobStatusEnum;
use AppWatch\Agent\Enums\LogLevelEnum;
use AppWatch\Agent\Payloads\EventMetadata;
use AppWatch\Agent\Payloads\EventPayload;
use AppWatch\Agent\Payloads\ExceptionEventPayload;
use AppWatch\Agent\Payloads\JobEventPayload;
use AppWatch\Agent\Payloads\LogEventPayload;
use AppWatch\Agent\Payloads\QueryEventPayload;
use AppWatch\Agent\Payloads\RequestEventPayload;
use AppWatch\Agent\Recorder;
use AppWatch\Agent\Tests\TestCase;
use AppWatch\Agent\Transport\FakeTransport;
use AppWatch\Agent\Transport\Transport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

pest()->extend(TestCase::class)->in(__DIR__);

/**
 * Every event of the trace: those already flushed to the fake transport (when its request, job
 * run or command ended), then those still buffered in the recorder (which are flushed now).
 *
 * @return array<int, EventPayload>
 */
function recordedEvents(string $trace_id): array
{
    $transport = app(Transport::class);
    $sent = $transport instanceof FakeTransport ? $transport->events($trace_id) : [];

    return [...$sent, ...app(Recorder::class)->flush($trace_id)];
}

/**
 * A local TCP server on an ephemeral port standing in for the agent daemon, with its port.
 *
 * @return array{0: resource, 1: int}
 */
function startTestDaemon(): array
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $error_code, $error_message);

    if (! is_resource($server)) {
        throw new RuntimeException("Could not start the test daemon: {$error_message}");
    }

    $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);

    return [$server, $port];
}

/**
 * A port nothing listens on (a test server's port, freed again).
 */
function unusedPort(): int
{
    [$server, $port] = startTestDaemon();
    fclose($server);

    return $port;
}

/**
 * Accept every connection already made to the test daemon and decode the NDJSON lines it got,
 * one array per connection. The client has already written and closed, so this never blocks
 * for long.
 *
 * @param  resource  $server
 * @return array<int, array<int, array<string, mixed>>>
 */
function readTestDaemon($server): array
{
    $connections = [];

    while (($connection = @stream_socket_accept($server, 0.1)) !== false) {
        $lines = array_filter(explode("\n", (string) stream_get_contents($connection)), fn (string $line): bool => $line !== '');
        $connections[] = array_values(array_map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines));
        fclose($connection);
    }

    return $connections;
}

/**
 * The event of the given type from the copied v1 contract fixture (tests/Fixtures/ingest-batch-v1.json).
 *
 * @return array<string, mixed>
 */
function fixtureEvent(string $type): array
{
    /** @var array{events: array<int, array<string, mixed>>} $batch */
    $batch = json_decode((string) file_get_contents(__DIR__.'/Fixtures/ingest-batch-v1.json'), true, flags: JSON_THROW_ON_ERROR);

    foreach ($batch['events'] as $event) {
        if ($event['type'] === $type) {
            return $event;
        }
    }

    throw new RuntimeException("No {$type} event in the fixture.");
}

/**
 * The common metadata of a fixture event.
 *
 * @param  array<string, mixed>  $event
 */
function fixtureMetadata(array $event): EventMetadata
{
    return new EventMetadata(
        trace_id: $event['trace_id'],
        recorded_at: new DateTimeImmutable($event['recorded_at']),
        host: $event['host'],
        deploy: $event['deploy'],
    );
}

/**
 * Assert the payload serializes to exactly the fixture event: same keys, order, types and values.
 *
 * @param  array<string, mixed>  $payload
 * @param  array<string, mixed>  $fixture
 */
function expectMatchesContract(array $payload, array $fixture): void
{
    expect(array_keys($payload))->toBe(array_keys($fixture))
        ->and(array_map(get_debug_type(...), $payload))->toBe(array_map(get_debug_type(...), $fixture))
        ->and($payload)->toBe($fixture);
}

/**
 * A request event for the given trace.
 */
function requestEvent(string $trace_id, string $path = '/orders/42'): RequestEventPayload
{
    return new RequestEventPayload(EventMetadata::capture($trace_id), 'GET', null, $path, 200, 10.0, null);
}

/**
 * A query event for the given trace.
 */
function queryEvent(string $trace_id, string $sql = 'select * from "orders"'): QueryEventPayload
{
    return new QueryEventPayload(EventMetadata::capture($trace_id), 'sqlite', $sql, sha1($sql), 1.0);
}

/**
 * A job event for the given trace.
 */
function jobEvent(string $trace_id, string $job_class = 'App\\Jobs\\SendOrderConfirmationJob'): JobEventPayload
{
    return new JobEventPayload(EventMetadata::capture($trace_id), $job_class, 'default', 'sync', JobStatusEnum::Processed, 1, 5.0);
}

/**
 * A log event for the given trace.
 */
function logEvent(string $trace_id, LogLevelEnum $level = LogLevelEnum::Error): LogEventPayload
{
    return new LogEventPayload(EventMetadata::capture($trace_id), $level, 'Something happened', null);
}

/**
 * An exception event for the given trace.
 */
function exceptionEvent(string $trace_id): ExceptionEventPayload
{
    return new ExceptionEventPayload(EventMetadata::capture($trace_id), RuntimeException::class, 'Boom', '/app/a.php', 1, [], false);
}

/**
 * The v1 contract fixture batch (tests/Fixtures/ingest-batch-v1.json).
 *
 * @return array{sent_at: string, environment: array<string, string>, events: array<int, array<string, mixed>>}
 */
function fixtureBatch(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/Fixtures/ingest-batch-v1.json'), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The given number of v1 events (copies of the fixture's request event, numbered by path).
 *
 * @return array<int, array<string, mixed>>
 */
function daemonEvents(int $count): array
{
    $event = fixtureEvent('request');

    if ($count < 1) {
        return [];
    }

    return array_map(static fn (int $index): array => [...$event, 'path' => "/orders/{$index}"], range(1, $count));
}

/**
 * Install the telemetry schema with the test clock frozen at the fixture's day, so the daemon can
 * write the fixture events (recorded 2026-11-03 05:41) into their partitions.
 */
function prepareTelemetryDatabase(string $now = '2026-11-03 05:41:00'): void
{
    CarbonImmutable::setTestNow($now);
    Carbon::setTestNow($now);
    installTelemetrySchema();
}

/**
 * Point the `app_watch` connection at port 1, where nothing listens, so every write fails with a
 * connection error (SQLSTATE 08006).
 */
function breakTelemetryConnection(): void
{
    config(['database.connections.app_watch.port' => 1]);
    DB::purge('app_watch');
}

/**
 * Point the `app_watch` connection back at the real test database.
 */
function restoreTelemetryConnection(): void
{
    config(['database.connections.app_watch.port' => (int) env('APP_WATCH_DB_PORT', 5432)]);
    DB::purge('app_watch');
}

/**
 * Make every insert into the given raw table fail with the given SQLSTATE (a BEFORE INSERT trigger
 * raising it), so the daemon's classification can be exercised against the real database.
 */
function failTelemetryInsertsWith(string $sqlstate, string $table = 'requests'): void
{
    DB::connection('app_watch')->unprepared(<<<SQL
        CREATE OR REPLACE FUNCTION app_watch_test_fail_insert() RETURNS trigger AS \$\$
        BEGIN
            RAISE EXCEPTION 'forced test failure' USING ERRCODE = '{$sqlstate}';
        END
        \$\$ LANGUAGE plpgsql;
        CREATE TRIGGER app_watch_test_fail_insert BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION app_watch_test_fail_insert();
        SQL);
}

/**
 * The paths of the stored request rows, in insert order.
 *
 * @return array<int, string>
 */
function storedRequestPaths(): array
{
    return DB::connection('app_watch')->table('requests')->orderBy('id')->pluck('path')->all();
}

/**
 * The events of every spooled batch, one array per spool file, oldest first.
 *
 * @return array<int, array<int, array<string, mixed>>>
 */
function spooledBatches(): array
{
    return array_map(static function (string $path): array {
        $json = gzdecode((string) file_get_contents($path));

        if ($json === false) {
            throw new RuntimeException("The spool file {$path} is not gzip.");
        }

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }, app(Spool::class)->files());
}

/**
 * Run the given callback with the environment variables set (or unset when null), then restore them.
 *
 * @param  array<string, string|null>  $variables
 */
function withEnvironmentVariables(array $variables, Closure $callback): mixed
{
    $original = [];

    foreach ($variables as $name => $value) {
        $original[$name] = getenv($name) === false ? null : (string) getenv($name);
        setEnvironmentVariable($name, $value);
    }

    try {
        return $callback();
    } finally {
        foreach ($original as $name => $value) {
            setEnvironmentVariable($name, $value);
        }
    }
}

/**
 * Set (or unset when null) one environment variable everywhere env() reads it from.
 */
function setEnvironmentVariable(string $name, ?string $value): void
{
    if ($value === null) {
        unset($_ENV[$name], $_SERVER[$name]);
        putenv($name);

        return;
    }

    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
    putenv("{$name}={$value}");
}

/**
 * Install the agent's telemetry schema on a clean `app_watch` connection, with the daily partitions
 * from today (Carbon's test "now" included) to `app-watch.partitions_ahead_days` ahead.
 */
function installTelemetrySchema(): void
{
    Schema::connection('app_watch')->dropAllTables();
    test()->artisan('app-watch:install', ['--database' => 'app_watch'])->assertExitCode(0);
}

/**
 * Drop every table of the `app_watch` connection.
 */
function dropTelemetrySchema(): void
{
    Schema::connection('app_watch')->dropAllTables();
}

/**
 * The fixture event of the given type with overrides applied.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function storageEvent(string $type, array $overrides = []): array
{
    return [...fixtureEvent($type), ...$overrides];
}

/**
 * The row count of every table the batch writer writes, by table.
 *
 * @return array<string, int>
 */
function telemetryRowCounts(): array
{
    $counts = [];

    foreach ([...AppWatch\Agent\Storage\Schema::PARTITIONED_TABLES, 'exception_groups'] as $table) {
        $counts[$table] = DB::connection('app_watch')->table($table)->count();
    }

    return $counts;
}

/**
 * Count the INSERT statements into the given table run on the `app_watch` connection from now on.
 *
 * @return ArrayObject<string, int>
 */
function countTelemetryInserts(string $table): ArrayObject
{
    $counter = new ArrayObject(['inserts' => 0]);

    DB::connection('app_watch')->listen(function (QueryExecuted $query) use ($counter, $table): void {
        if (str_starts_with($query->sql, "insert into \"{$table}\"")) {
            $counter['inserts']++;
        }
    });

    return $counter;
}

/**
 * Install the agent's telemetry schema on a clean `app_watch` connection, then drop every daily
 * partition it created, so partition upkeep starts from tables without partitions.
 */
function installTelemetrySchemaWithoutPartitions(): void
{
    installTelemetrySchema();

    foreach (AppWatch\Agent\Storage\Schema::PARTITIONED_TABLES as $table) {
        foreach (telemetryPartitionsOf($table) as $partition) {
            DB::connection('app_watch')->statement("DROP TABLE {$partition}");
        }
    }
}

/**
 * The partitions of a raw table on the `app_watch` connection, from pg_inherits, sorted by name.
 *
 * @return list<string>
 */
function telemetryPartitionsOf(string $table): array
{
    /** @var list<string> $partitions */
    $partitions = collect(DB::connection('app_watch')->select(
        'SELECT child.relname FROM pg_inherits
         JOIN pg_class parent ON parent.oid = pg_inherits.inhparent
         JOIN pg_class child ON child.oid = pg_inherits.inhrelid
         WHERE parent.relname = ? ORDER BY child.relname',
        [$table],
    ))->pluck('relname')->values()->all();

    return $partitions;
}

/**
 * The partition bound expression of a partition on the `app_watch` connection.
 */
function telemetryPartitionBound(string $partition): mixed
{
    return DB::connection('app_watch')->scalar('SELECT pg_get_expr(relpartbound, oid) FROM pg_class WHERE relname = ?', [$partition]);
}

/**
 * Create the daily partition `{table}_pYYYYMMDD` of a raw table for the given UTC day by hand.
 */
function createTelemetryPartition(string $table, CarbonImmutable $day): string
{
    $day = $day->utc()->startOfDay();
    $partition = $table.'_p'.$day->format('Ymd');

    DB::connection('app_watch')->statement(sprintf(
        "CREATE TABLE %s PARTITION OF %s FOR VALUES FROM ('%s 00:00:00+00') TO ('%s 00:00:00+00')",
        $partition,
        $table,
        $day->format('Y-m-d'),
        $day->addDay()->format('Y-m-d'),
    ));

    return $partition;
}
