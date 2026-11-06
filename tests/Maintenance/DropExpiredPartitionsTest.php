<?php

declare(strict_types=1);

use AppWatch\Agent\Maintenance\CreatePartitions;
use AppWatch\Agent\Maintenance\DropExpiredPartitions;
use AppWatch\Agent\Storage\Schema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->today = CarbonImmutable::parse('2026-03-20 00:00:00', 'UTC');
    CarbonImmutable::setTestNow($this->today->addHours(10));
    installTelemetrySchemaWithoutPartitions();
    app(CreatePartitions::class)->handle($this->today->subDays(20), 27);
    $this->drop_expired_partitions = app(DropExpiredPartitions::class);
});

afterEach(function (): void {
    dropTelemetrySchema();
});

/**
 * The partition name of a raw table for the day `$days_ago` days before 2026-03-20 (UTC).
 */
function expiredPartitionName(string $table, int $days_ago): string
{
    return $table.'_p'.CarbonImmutable::parse('2026-03-20', 'UTC')->subDays($days_ago)->format('Ymd');
}

/**
 * Whether the table exists on the `app_watch` connection.
 */
function telemetryTableExists(string $table): bool
{
    return DB::connection('app_watch')->scalar('SELECT to_regclass(?) IS NOT NULL', [$table]) === true;
}

it('drops partitions whose upper bound is at or before now - retention_days and keeps the rest', function (): void {
    $dropped = $this->drop_expired_partitions->handle(CarbonImmutable::now('UTC'), 14);

    // Days today-20 … today-15 end at or before today-14 00:00 UTC: 6 days × 9 tables.
    expect($dropped)->toBe(54);

    foreach (Schema::PARTITIONED_TABLES as $table) {
        foreach (range(15, 20) as $days_ago) {
            expect(telemetryTableExists(expiredPartitionName($table, $days_ago)))->toBeFalse();
        }

        foreach (range(0, 14) as $days_ago) {
            expect(telemetryTableExists(expiredPartitionName($table, $days_ago)))->toBeTrue();
        }

        expect(telemetryTableExists($table.'_p'.$this->today->addDays(7)->format('Ymd')))->toBeTrue();
    }
});

it('drops the partition ending exactly at the cutoff and keeps the one holding data inside the window', function (): void {
    DB::connection('app_watch')->table('logs')->insert([
        ['environment_id' => 1, 'trace_id' => '0b6f5a6e-3c1d-4e7a-9a59-0a2f1d7c8e11', 'recorded_at' => $this->today->subDays(14)->addHours(3), 'host' => 'web-1', 'level' => 'info', 'message' => 'inside the window'],
        ['environment_id' => 1, 'trace_id' => '0b6f5a6e-3c1d-4e7a-9a59-0a2f1d7c8e12', 'recorded_at' => $this->today->subDays(15)->addHours(23), 'host' => 'web-1', 'level' => 'info', 'message' => 'expired'],
    ]);

    // now is exactly midnight: the cutoff is today - 14 days 00:00 UTC.
    $this->drop_expired_partitions->handle($this->today, 14);

    expect(telemetryTableExists(expiredPartitionName('logs', 15)))->toBeFalse()
        ->and(telemetryTableExists(expiredPartitionName('logs', 14)))->toBeTrue()
        ->and(DB::connection('app_watch')->table('logs')->pluck('message')->all())->toBe(['inside the window']);
});

it('uses the UTC start of day of now whatever its timezone and the host app timezone', function (): void {
    config(['app.timezone' => 'Asia/Manila']);
    date_default_timezone_set('Asia/Manila');

    try {
        // 07:00 in Manila on today is 23:00 UTC on yesterday, so the cutoff is yesterday - 14 days.
        $this->drop_expired_partitions->handle($this->today->setTimezone('Asia/Manila')->setTime(7, 0), 14);
    } finally {
        date_default_timezone_set('UTC');
    }

    expect(telemetryTableExists(expiredPartitionName('requests', 16)))->toBeFalse()
        ->and(telemetryTableExists(expiredPartitionName('requests', 15)))->toBeTrue();
});

it('is idempotent: a second run drops nothing', function (): void {
    expect($this->drop_expired_partitions->handle(CarbonImmutable::now('UTC'), 14))->toBe(54)
        ->and($this->drop_expired_partitions->handle(CarbonImmutable::now('UTC'), 14))->toBe(0);
});

it("never drops today's partition, even with the smallest retention", function (): void {
    $this->drop_expired_partitions->handle(CarbonImmutable::now('UTC'), 1);

    foreach (Schema::PARTITIONED_TABLES as $table) {
        expect(telemetryTableExists(expiredPartitionName($table, 0)))->toBeTrue()
            ->and(telemetryTableExists(expiredPartitionName($table, 1)))->toBeTrue()
            ->and(telemetryTableExists(expiredPartitionName($table, 2)))->toBeFalse();
    }
});

it('rejects a retention below one day without dropping anything', function (int $retention_days): void {
    expect(fn () => $this->drop_expired_partitions->handle(CarbonImmutable::now('UTC'), $retention_days))
        ->toThrow(InvalidArgumentException::class, 'retention_days must be at least 1.')
        ->and(telemetryTableExists(expiredPartitionName('requests', 20)))->toBeTrue();
})->with([0, -5]);

it('ignores child tables that do not match the {table}_pYYYYMMDD naming scheme', function (): void {
    $old_day = $this->today->subDays(40);
    DB::connection('app_watch')->statement(sprintf(
        "CREATE TABLE requests_archive PARTITION OF requests FOR VALUES FROM ('%s') TO ('%s')",
        $old_day->format('Y-m-d 00:00:00+00'),
        $old_day->addDay()->format('Y-m-d 00:00:00+00'),
    ));
    DB::connection('app_watch')->statement(sprintf(
        "CREATE TABLE requests_p%s_old PARTITION OF requests FOR VALUES FROM ('%s') TO ('%s')",
        $old_day->subDay()->format('Ymd'),
        $old_day->subDay()->format('Y-m-d 00:00:00+00'),
        $old_day->format('Y-m-d 00:00:00+00'),
    ));

    expect($this->drop_expired_partitions->handle(CarbonImmutable::now('UTC'), 14))->toBe(54)
        ->and(telemetryTableExists('requests_archive'))->toBeTrue()
        ->and(telemetryTableExists('requests_p'.$old_day->subDay()->format('Ymd').'_old'))->toBeTrue();
});

it('never drops a table that only matches the name pattern', function (): void {
    DB::connection('app_watch')->statement('CREATE TABLE other_table_20260101 (id bigint)');
    DB::connection('app_watch')->statement('CREATE TABLE other_table_p20260101 (id bigint)');
    DB::connection('app_watch')->statement('CREATE TABLE requests_p20260101 (id bigint)');

    $this->drop_expired_partitions->handle(CarbonImmutable::now('UTC'), 14);

    expect(telemetryTableExists('other_table_20260101'))->toBeTrue()
        ->and(telemetryTableExists('other_table_p20260101'))->toBeTrue()
        ->and(telemetryTableExists('requests_p20260101'))->toBeTrue();
});

it('only drops partitions of the configured tables', function (): void {
    config(['app-watch.tables' => ['logs']]);

    expect($this->drop_expired_partitions->handle(CarbonImmutable::now('UTC'), 14))->toBe(6)
        ->and(telemetryTableExists(expiredPartitionName('requests', 20)))->toBeTrue()
        ->and(telemetryTableExists(expiredPartitionName('logs', 20)))->toBeFalse();
});

it('drops nothing when no tables are configured', function (): void {
    config(['app-watch.tables' => []]);

    expect($this->drop_expired_partitions->handle(CarbonImmutable::now('UTC'), 14))->toBe(0)
        ->and(telemetryTableExists(expiredPartitionName('requests', 20)))->toBeTrue();
});
