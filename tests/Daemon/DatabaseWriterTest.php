<?php

declare(strict_types=1);

use AppWatch\Agent\Daemon\DatabaseWriter;
use AppWatch\Agent\Daemon\WriteResult;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    prepareTelemetryDatabase();

    $this->writer = app(DatabaseWriter::class);
});

afterEach(function (): void {
    restoreTelemetryConnection();
    dropTelemetrySchema();
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});

it('stores the events in their raw tables under the configured environment', function (): void {
    $events = [...daemonEvents(3), fixtureEvent('log'), fixtureEvent('query')];

    $result = $this->writer->send($events);

    expect($result)->toBeInstanceOf(WriteResult::class)
        ->and($result->stored_count)->toBe(5)
        ->and($result->poison_count)->toBe(0)
        ->and($result->skipped_count)->toBe(0)
        ->and($result->shouldRetry())->toBeFalse()
        ->and($result->failure)->toBeNull()
        ->and(storedRequestPaths())->toBe(['/orders/1', '/orders/2', '/orders/3'])
        ->and(DB::connection('app_watch')->table('requests')->distinct()->pluck('environment_id')->all())->toBe([1])
        ->and(telemetryRowCounts())->toMatchArray(['logs' => 1, 'queries' => 1]);
});

it('writes at most 1000 events per transaction', function (): void {
    $transactions = 0;
    DB::connection('app_watch')->beforeStartingTransaction(function (Connection $connection) use (&$transactions): void {
        if ($connection->transactionLevel() === 0) {
            $transactions++;
        }
    });

    $result = $this->writer->send(daemonEvents(2500));

    expect($result->stored_count)->toBe(2500)
        ->and($transactions)->toBe(3)
        ->and(DB::connection('app_watch')->table('requests')->count())->toBe(2500);
});

it('applies the statement timeout to every chunk', function (int $timeout_seconds, string $expected): void {
    $statements = [];
    DB::connection('app_watch')->listen(function (QueryExecuted $query) use (&$statements): void {
        if (str_starts_with($query->sql, 'SET LOCAL statement_timeout')) {
            $statements[] = $query->sql;
        }
    });

    $this->writer->send(daemonEvents(1001), $timeout_seconds);

    expect($statements)->toBe([$expected, $expected]);
})->with([
    'the default' => [DatabaseWriter::TIMEOUT_SECONDS, 'SET LOCAL statement_timeout = 10000'],
    'the shutdown timeout' => [3, 'SET LOCAL statement_timeout = 3000'],
    'at least one second' => [0, 'SET LOCAL statement_timeout = 1000'],
]);

it('counts events the batch writer skips as malformed or outside retention', function (): void {
    $result = $this->writer->send([
        ...daemonEvents(2),
        ['type' => 'request'],
        [...fixtureEvent('request'), 'recorded_at' => '2026-10-01T00:00:00.000000+00:00'],
    ]);

    expect($result->stored_count)->toBe(2)
        ->and($result->skipped_count)->toBe(2)
        ->and($result->shouldRetry())->toBeFalse();
});

it('hands every event back as pending when the database cannot be reached', function (): void {
    breakTelemetryConnection();
    $events = daemonEvents(1500);

    $result = $this->writer->send($events);

    expect($result->shouldRetry())->toBeTrue()
        ->and($result->pending)->toBe($events)
        ->and($result->stored_count)->toBe(0)
        ->and($result->poison_count)->toBe(0)
        ->and($result->failure)->toContain('08006')
        ->and($result->failure)->toContain('Connection refused');

    restoreTelemetryConnection();

    expect($this->writer->send($result->pending)->stored_count)->toBe(1500);
});

it('classifies a failing chunk by its SQLSTATE', function (string $sqlstate, bool $poison): void {
    failTelemetryInsertsWith($sqlstate);
    $events = daemonEvents(2);

    $result = $this->writer->send($events);

    expect($result->isPoison())->toBe($poison)
        ->and($result->shouldRetry())->toBe(! $poison)
        ->and($result->poison_count)->toBe($poison ? 2 : 0)
        ->and($result->poison_sqlstate)->toBe($poison ? $sqlstate : null)
        ->and($result->pending)->toBe($poison ? [] : $events)
        ->and(DB::connection('app_watch')->table('requests')->count())->toBe(0);

    if ($poison) {
        expect($result->failure)->toBeNull();
    } else {
        expect($result->failure)->toStartWith("SQLSTATE[{$sqlstate}]")->toContain('forced test failure');
    }
})->with([
    'invalid text representation (22P02) is poison' => ['22P02', true],
    'numeric value out of range (22003) is poison' => ['22003', true],
    'check violation (23514) is poison' => ['23514', true],
    'not-null violation (23502) is poison' => ['23502', true],
    'deadlock (40P01) is retryable' => ['40P01', false],
    'query canceled by statement timeout (57014) is retryable' => ['57014', false],
    'too many connections (53300) is retryable' => ['53300', false],
    'invalid transaction state (25000) is retryable' => ['25000', false],
    'a generic raise_exception (P0001) is retryable' => ['P0001', false],
]);

it('treats an error without an SQLSTATE as retryable', function (): void {
    config(['app-watch.environment_id' => null]);
    $events = daemonEvents(1);

    $result = $this->writer->send($events);

    expect($result->shouldRetry())->toBeTrue()
        ->and($result->pending)->toBe($events)
        ->and($result->failure)->toBe('app-watch.environment_id is not configured.');
});

it('drops a chunk whose recorded_at day has no partition and stores the other chunks', function (): void {
    DB::connection('app_watch')->statement('DROP TABLE requests_p20261104');
    $events = daemonEvents(2001);
    $events[1500] = [...$events[1500], 'recorded_at' => '2026-11-04T10:00:00.000000+00:00'];

    $result = $this->writer->send($events);

    expect($result->stored_count)->toBe(1001)
        ->and($result->poison_count)->toBe(1000)
        ->and($result->poison_sqlstate)->toBe('23514')
        ->and($result->isMissingPartition())->toBeTrue()
        ->and($result->shouldRetry())->toBeFalse()
        ->and(storedRequestPaths())->toBe([...array_map(static fn (int $index): string => "/orders/{$index}", range(1, 1000)), '/orders/2001']);
});

it('stops at the first retryable chunk, keeping what was stored and counted before it', function (): void {
    $events = daemonEvents(2500);
    $events[100] = [...$events[100], 'path' => '/poison'];
    $events[1100] = [...$events[1100], 'path' => '/deadlock'];
    DB::connection('app_watch')->unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION app_watch_test_fail_insert() RETURNS trigger AS $$
        BEGIN
            IF NEW.path = '/poison' THEN
                RAISE EXCEPTION 'bad row' USING ERRCODE = '22P02';
            END IF;
            IF NEW.path = '/deadlock' THEN
                RAISE EXCEPTION 'deadlock' USING ERRCODE = '40P01';
            END IF;
            RETURN NEW;
        END
        $$ LANGUAGE plpgsql;
        CREATE TRIGGER app_watch_test_fail_insert BEFORE INSERT ON requests FOR EACH ROW EXECUTE FUNCTION app_watch_test_fail_insert();
        SQL);

    $result = $this->writer->send($events);

    expect($result->poison_count)->toBe(1000)
        ->and($result->stored_count)->toBe(0)
        ->and($result->pending)->toBe(array_slice($events, 1000))
        ->and(DB::connection('app_watch')->table('requests')->count())->toBe(0);
});

it('never puts the password, the query or its data in the failure', function (): void {
    config(['database.connections.app_watch.password' => 'super-secret-password', 'database.connections.app_watch.port' => 1]);
    DB::purge('app_watch');

    $connection_failure = $this->writer->send(daemonEvents(1))->failure;

    restoreTelemetryConnection();
    failTelemetryInsertsWith('40P01');

    $query_failure = $this->writer->send([[...fixtureEvent('request'), 'path' => '/secret-path']])->failure;

    expect($connection_failure)->not->toContain('super-secret-password')
        ->and($query_failure)->not->toContain('/secret-path')
        ->and($query_failure)->not->toContain('insert into')
        ->and($query_failure)->not->toContain('Host:')
        ->and(mb_strlen((string) $connection_failure))->toBeLessThanOrEqual(DatabaseWriter::MAX_FAILURE_LENGTH);
});

it('reconnects after the database comes back', function (): void {
    expect($this->writer->send(daemonEvents(1))->stored_count)->toBe(1);

    breakTelemetryConnection();

    expect($this->writer->send(daemonEvents(1))->shouldRetry())->toBeTrue();

    restoreTelemetryConnection();

    expect($this->writer->send(daemonEvents(1))->stored_count)->toBe(1)
        ->and(DB::connection('app_watch')->table('requests')->count())->toBe(2);
});
