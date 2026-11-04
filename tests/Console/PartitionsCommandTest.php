<?php

declare(strict_types=1);

use AppWatch\Agent\Storage\PartitionDdl;
use AppWatch\Agent\Storage\Schema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->today = CarbonImmutable::parse('2026-03-20 00:00:00', 'UTC');
    CarbonImmutable::setTestNow($this->today->addHours(10));
    installTelemetrySchemaWithoutPartitions();
});

afterEach(function (): void {
    date_default_timezone_set('UTC');
    dropTelemetrySchema();
});

/**
 * The number of daily partitions of the raw telemetry tables on the `app_watch` connection.
 */
function telemetryPartitionCount(): int
{
    return app(PartitionDdl::class)->count(DB::connection('app_watch'));
}

it('creates today … today + 7 for every table on an empty database, then nothing on a second run', function (): void {
    $this->artisan('app-watch:partitions')
        ->expectsOutputToContain('Created 56 partition(s), dropped 0 partition(s).')
        ->assertExitCode(0);

    expect(telemetryPartitionCount())->toBe(56);

    foreach (Schema::PARTITIONED_TABLES as $table) {
        expect(telemetryPartitionsOf($table))->toBe(
            array_map(fn (int $offset): string => $table.'_p'.$this->today->addDays($offset)->format('Ymd'), range(0, 7)),
        );
    }

    $this->artisan('app-watch:partitions')
        ->expectsOutputToContain('Created 0 partition(s), dropped 0 partition(s).')
        ->assertExitCode(0);

    expect(telemetryPartitionCount())->toBe(56);
});

it('drops exactly the partitions older than retention_days', function (): void {
    config(['app-watch.retention_days' => 14]);
    $this->artisan('app-watch:partitions')->assertExitCode(0);
    $expired = [
        createTelemetryPartition('requests', $this->today->subDays(20)),
        createTelemetryPartition('logs', $this->today->subDays(20)),
    ];

    $this->artisan('app-watch:partitions')
        ->expectsOutputToContain('Created 0 partition(s), dropped 2 partition(s).')
        ->assertExitCode(0);

    expect(telemetryPartitionsOf('requests'))->not->toContain($expired[0])
        ->and(telemetryPartitionsOf('logs'))->not->toContain($expired[1])
        ->and(telemetryPartitionCount())->toBe(56);
});

it('never drops a non-whitelisted table that matches the partition name pattern', function (): void {
    DB::connection('app_watch')->statement('CREATE TABLE other_table_20260101 (id bigint)');
    DB::connection('app_watch')->statement('CREATE TABLE other_table_p20260101 (id bigint)');

    $this->artisan('app-watch:partitions')
        ->expectsOutputToContain('dropped 0 partition(s).')
        ->assertExitCode(0);

    expect(DB::connection('app_watch')->scalar("SELECT to_regclass('other_table_20260101') IS NOT NULL"))->toBeTrue()
        ->and(DB::connection('app_watch')->scalar("SELECT to_regclass('other_table_p20260101') IS NOT NULL"))->toBeTrue();
});

it('fails without creating or dropping anything when retention_days is below 1', function (): void {
    config(['app-watch.retention_days' => 0]);
    $expired = createTelemetryPartition('requests', $this->today->subDays(20));

    $this->artisan('app-watch:partitions')
        ->expectsOutputToContain('retention_days must be at least 1.')
        ->assertExitCode(1);

    expect(telemetryPartitionsOf('requests'))->toBe([$expired])
        ->and(telemetryPartitionCount())->toBe(1);
});

it('fails without creating or dropping anything when a configured table is not whitelisted', function (): void {
    config(['app-watch.tables' => ['requests', 'users']]);

    $this->artisan('app-watch:partitions')
        ->expectsOutputToContain('Invalid partitioned table name in app-watch.tables.')
        ->assertExitCode(1);

    expect(telemetryPartitionCount())->toBe(0);
});

it('creates the same UTC partitions whatever the host timezone', function (string $timezone): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-01 23:30:00', 'UTC'));
    config(['app.timezone' => $timezone]);
    date_default_timezone_set($timezone);

    $this->artisan('app-watch:partitions')
        ->expectsOutputToContain('Created 56 partition(s), dropped 0 partition(s).')
        ->assertExitCode(0);

    expect(telemetryPartitionsOf('requests'))->toBe([
        'requests_p20260301', 'requests_p20260302', 'requests_p20260303', 'requests_p20260304',
        'requests_p20260305', 'requests_p20260306', 'requests_p20260307', 'requests_p20260308',
    ])->and(telemetryPartitionBound('requests_p20260301'))
        ->toBe("FOR VALUES FROM ('2026-03-01 00:00:00+00') TO ('2026-03-02 00:00:00+00')");
})->with(['UTC', 'Asia/Manila']);

it('uses the configured partitions_ahead_days', function (): void {
    config(['app-watch.partitions_ahead_days' => 2]);

    $this->artisan('app-watch:partitions')
        ->expectsOutputToContain('Created 21 partition(s), dropped 0 partition(s).')
        ->assertExitCode(0);
});

it('runs on the --database connection', function (): void {
    config([
        'database.connections.client' => config('database.connections.app_watch'),
        'app-watch.connection' => 'missing_connection',
    ]);

    $this->artisan('app-watch:partitions', ['--database' => 'client'])
        ->expectsOutputToContain('Created 56 partition(s), dropped 0 partition(s).')
        ->assertExitCode(0);
});

it('fails without changing anything when the connection does not exist', function (): void {
    $this->artisan('app-watch:partitions', ['--database' => 'missing_connection'])
        ->expectsOutputToContain('Database connection [missing_connection] is not configured')
        ->assertExitCode(1);

    expect(telemetryPartitionCount())->toBe(0);
});
