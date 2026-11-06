<?php

declare(strict_types=1);

use AppWatch\Agent\Maintenance\CreatePartitions;
use AppWatch\Agent\Storage\Schema;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    installTelemetrySchemaWithoutPartitions();
    $this->create_partitions = app(CreatePartitions::class);
});

afterEach(function (): void {
    dropTelemetrySchema();
});

it('creates one partition per table and UTC day in [now, now + ahead_days] with UTC midnight bounds', function (): void {
    $created = $this->create_partitions->handle(CarbonImmutable::parse('2025-03-01 15:00:00', 'UTC'), 2);

    expect($created)->toBe(27);

    foreach (Schema::PARTITIONED_TABLES as $table) {
        expect(telemetryPartitionsOf($table))->toBe(["{$table}_p20250301", "{$table}_p20250302", "{$table}_p20250303"]);
    }

    expect(telemetryPartitionBound('requests_p20250301'))
        ->toBe("FOR VALUES FROM ('2025-03-01 00:00:00+00') TO ('2025-03-02 00:00:00+00')");
});

it('is idempotent: a second run creates nothing', function (): void {
    $now = CarbonImmutable::parse('2025-03-01', 'UTC');

    expect($this->create_partitions->handle($now, 3))->toBe(36)
        ->and($this->create_partitions->handle($now, 3))->toBe(0)
        ->and(telemetryPartitionsOf('logs'))->toContain('logs_p20250304');
});

it('only counts the partitions it actually creates', function (): void {
    $this->create_partitions->handle(CarbonImmutable::parse('2025-03-02', 'UTC'), 0);

    expect($this->create_partitions->handle(CarbonImmutable::parse('2025-03-01', 'UTC'), 2))->toBe(18);
});

it('creates a single day when ahead_days is 0', function (): void {
    expect($this->create_partitions->handle(CarbonImmutable::parse('2025-03-01', 'UTC'), 0))->toBe(9);
});

it('only creates partitions of the configured tables', function (): void {
    config(['app-watch.tables' => ['logs']]);

    expect($this->create_partitions->handle(CarbonImmutable::parse('2025-03-01', 'UTC'), 0))->toBe(1)
        ->and(telemetryPartitionsOf('logs'))->toBe(['logs_p20250301'])
        ->and(telemetryPartitionsOf('requests'))->toBe([]);
});

it('uses UTC day boundaries whatever the timezone of now and of the host app', function (): void {
    config(['app.timezone' => 'Asia/Manila']);
    date_default_timezone_set('Asia/Manila');

    try {
        // 07:00 in Manila on 2025-03-02 is 23:00 UTC on 2025-03-01.
        $this->create_partitions->handle(CarbonImmutable::parse('2025-03-02 07:00:00', 'Asia/Manila'), 0);
    } finally {
        date_default_timezone_set('UTC');
    }

    expect(telemetryPartitionsOf('requests'))->toBe(['requests_p20250301'])
        ->and(telemetryPartitionBound('requests_p20250301'))
        ->toBe("FOR VALUES FROM ('2025-03-01 00:00:00+00') TO ('2025-03-02 00:00:00+00')");
});

it('creates the partitions on the given connection', function (): void {
    config(['database.connections.client' => config('database.connections.app_watch')]);
    config(['app-watch.connection' => 'missing_connection']);

    expect($this->create_partitions->handle(CarbonImmutable::parse('2025-03-01', 'UTC'), 0, 'client'))->toBe(9)
        ->and(telemetryPartitionsOf('requests'))->toBe(['requests_p20250301']);
});

it('rejects a negative ahead_days without creating anything', function (): void {
    expect(fn () => $this->create_partitions->handle(CarbonImmutable::now('UTC'), -1))
        ->toThrow(InvalidArgumentException::class, 'days_ahead must be zero or more.')
        ->and(telemetryPartitionsOf('requests'))->toBe([]);
});

it('rejects a configured table name outside the whitelist without creating anything', function (): void {
    config(['app-watch.tables' => ['requests', 'requests; DROP TABLE logs']]);

    expect(fn () => $this->create_partitions->handle(CarbonImmutable::now('UTC'), 0))
        ->toThrow(InvalidArgumentException::class, 'Invalid partitioned table name in app-watch.tables.')
        ->and(telemetryPartitionsOf('requests'))->toBe([]);
});

it('leaves a day without a partition rejecting inserts', function (): void {
    $this->create_partitions->handle(CarbonImmutable::parse('2025-03-01', 'UTC'), 0);

    DB::connection('app_watch')->transaction(fn () => DB::connection('app_watch')->table('logs')->insert([
        'environment_id' => 1,
        'trace_id' => '0b6f5a6e-3c1d-4e7a-9a59-0a2f1d7c8e11',
        'recorded_at' => '2025-03-02 00:00:00+00',
        'host' => 'web-1',
        'level' => 'error',
        'message' => 'no partition',
    ]));
})->throws(QueryException::class, 'no partition of relation "logs" found for row');
