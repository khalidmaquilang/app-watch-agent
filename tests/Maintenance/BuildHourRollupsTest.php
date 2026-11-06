<?php

declare(strict_types=1);

use AppWatch\Agent\Maintenance\BuildHourRollups;
use AppWatch\Agent\Maintenance\BuildMinuteRollups;
use AppWatch\Agent\Tests\Support\TelemetryRows;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    config(['app-watch.rollups.grace_minutes' => 2]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-03 13:30:00', 'UTC'));
    installTelemetrySchema();

    $this->builder = app(BuildHourRollups::class);
    $this->hour = CarbonImmutable::parse('2026-11-03 12:00:00', 'UTC');
});

afterEach(function (): void {
    dropTelemetrySchema();
});

/**
 * The buckets of the hour rollups as `Y-m-d H:i`, oldest first.
 *
 * @return list<string>
 */
function hourRollupBuckets(): array
{
    $buckets = array_map(
        static fn (array $rollup): string => CarbonImmutable::parse($rollup['bucket'])->format('Y-m-d H:i'),
        TelemetryRows::rollups('rollups_hour'),
    );
    sort($buckets);

    return $buckets;
}

it('rolls up the last closed hour with percentiles computed over the raw rows', function (): void {
    foreach (range(1, 100) as $duration) {
        TelemetryRows::request($this->hour->addSeconds(($duration - 1) * 35), ['duration_ms' => $duration, 'status_code' => $duration <= 3 ? 500 : 200]);
        TelemetryRows::request($this->hour->addSeconds(($duration - 1) * 35 + 1), ['duration_ms' => $duration * 2, 'route' => 'posts']);
    }

    expect($this->builder->handle())->toBe(2);

    $rows = collect(TelemetryRows::rollups('rollups_hour'))->keyBy('key');

    expect($rows)->toHaveCount(2)
        ->and($rows['GET users/{user}']['bucket'])->toBe('2026-11-03T12:00:00+00:00')
        ->and($rows['GET users/{user}']['count'])->toBe(100)
        ->and($rows['GET users/{user}']['error_count'])->toBe(3)
        ->and((float) $rows['GET users/{user}']['duration_sum'])->toEqualWithDelta(5050, 0.01)
        ->and((float) $rows['GET users/{user}']['p50'])->toEqualWithDelta(50.5, 0.01)
        ->and((float) $rows['GET users/{user}']['p95'])->toEqualWithDelta(95.05, 0.01)
        ->and((float) $rows['GET users/{user}']['p99'])->toEqualWithDelta(99.01, 0.01)
        ->and((float) $rows['GET users/{user}']['max'])->toEqualWithDelta(100, 0.01)
        ->and((float) $rows['GET posts']['p95'])->toEqualWithDelta(190.1, 0.01);
});

it('rolls up command runs per command over the hour with failures as errors, idempotently', function (): void {
    foreach ([100, 200, 300] as $index => $duration) {
        TelemetryRows::commandRun($this->hour->addMinutes($index * 20), ['command' => 'a:b', 'duration_ms' => $duration, 'failed' => $duration === 200, 'exit_code' => $duration === 200 ? 1 : 0]);
    }

    expect($this->builder->handle())->toBe(1);

    $rows = TelemetryRows::rollups('rollups_hour');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['type'])->toBe('command')
        ->and($rows[0]['key'])->toBe('a:b')
        ->and($rows[0]['bucket'])->toBe('2026-11-03T12:00:00+00:00')
        ->and($rows[0]['count'])->toBe(3)
        ->and($rows[0]['error_count'])->toBe(1)
        ->and((float) $rows[0]['p95'])->toEqualWithDelta(290, 0.01)
        ->and((float) $rows[0]['max'])->toEqualWithDelta(300, 0.01);

    $this->builder->handle();

    expect(TelemetryRows::rollups('rollups_hour'))->toEqual($rows);
});

it('differs from the average of the minute p95s', function (): void {
    foreach (range(1, 59) as $second) {
        TelemetryRows::request($this->hour->addSeconds($second), ['duration_ms' => 1]);
    }

    foreach (range(1, 19) as $second) {
        TelemetryRows::request($this->hour->addMinute()->addSeconds($second), ['duration_ms' => 1000]);
    }

    TelemetryRows::request($this->hour->addMinute()->addSeconds(30), ['duration_ms' => 1]);

    foreach (['2026-11-03 12:01:00', '2026-11-03 12:02:00'] as $minute_now) {
        app(BuildMinuteRollups::class)->handle(CarbonImmutable::parse($minute_now, 'UTC'));
    }

    $average_minute_p95 = (float) DB::connection('app_watch')->table('rollups_minute')->avg('p95');

    $this->builder->handle();

    $rows = TelemetryRows::rollups('rollups_hour');

    expect(DB::connection('app_watch')->table('rollups_minute')->count())->toBe(2)
        ->and($rows)->toHaveCount(1)
        ->and((float) $rows[0]['p95'])->toEqualWithDelta(1000, 0.01)
        ->and($average_minute_p95)->toEqualWithDelta(500.5, 0.01);
});

it('yields identical rows when run twice for the same now', function (): void {
    foreach (range(1, 20) as $duration) {
        TelemetryRows::request($this->hour->addMinutes($duration), ['duration_ms' => $duration]);
    }

    $this->builder->handle();
    $first_run = TelemetryRows::rollups('rollups_hour');

    $this->builder->handle();

    expect(TelemetryRows::rollups('rollups_hour'))->toEqual($first_run)
        ->and($first_run)->toHaveCount(1);
});

it('aggregates a range crossing midnight across two daily partitions', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-04 01:00:30', 'UTC'));

    TelemetryRows::request(CarbonImmutable::parse('2026-11-03 23:30:00', 'UTC'), ['duration_ms' => 10]);
    TelemetryRows::request(CarbonImmutable::parse('2026-11-04 00:30:00', 'UTC'), ['duration_ms' => 20]);

    expect($this->builder->handle())->toBe(2)
        ->and(hourRollupBuckets())->toBe(['2026-11-03 23:00', '2026-11-04 00:00']);
});

it('never rolls up the current open hour', function (): void {
    TelemetryRows::request(CarbonImmutable::parse('2026-11-03 13:00:00', 'UTC'));
    TelemetryRows::request(CarbonImmutable::parse('2026-11-03 13:29:59', 'UTC'));

    expect($this->builder->handle())->toBe(0)
        ->and(DB::connection('app_watch')->table('rollups_hour')->count())->toBe(0);
});

it('produces no rows for an empty hour', function (): void {
    expect($this->builder->handle())->toBe(0)
        ->and(DB::connection('app_watch')->table('rollups_hour')->count())->toBe(0);
});

it('re-aggregates the previous hour within grace_minutes after the boundary', function (): void {
    TelemetryRows::request(CarbonImmutable::parse('2026-11-03 11:59:00', 'UTC'));
    TelemetryRows::request(CarbonImmutable::parse('2026-11-03 12:59:00', 'UTC'));

    $this->builder->handle(CarbonImmutable::parse('2026-11-03 13:01:59', 'UTC'));

    expect(hourRollupBuckets())->toBe(['2026-11-03 11:00', '2026-11-03 12:00']);
});

it('only builds the last closed hour once grace_minutes have passed', function (): void {
    TelemetryRows::request(CarbonImmutable::parse('2026-11-03 11:59:00', 'UTC'));
    TelemetryRows::request(CarbonImmutable::parse('2026-11-03 12:59:00', 'UTC'));

    $this->builder->handle(CarbonImmutable::parse('2026-11-03 13:02:00', 'UTC'));

    expect(hourRollupBuckets())->toBe(['2026-11-03 12:00']);
});

it('counts a late event for the last closed hour on the next run', function (): void {
    TelemetryRows::request($this->hour->addMinutes(10), ['duration_ms' => 10]);
    $this->builder->handle(CarbonImmutable::parse('2026-11-03 13:00:05', 'UTC'));

    TelemetryRows::request($this->hour->addMinutes(59), ['duration_ms' => 30]);
    $this->builder->handle(CarbonImmutable::parse('2026-11-03 13:05:00', 'UTC'));

    $rows = TelemetryRows::rollups('rollups_hour');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['count'])->toBe(2)
        ->and((float) $rows[0]['max'])->toEqualWithDelta(30, 0.01);
});

it('floors now to the UTC hour whatever its timezone', function (): void {
    TelemetryRows::request($this->hour->addMinutes(5));

    expect($this->builder->handle(CarbonImmutable::parse('2026-11-03 21:30:00', 'Asia/Manila')))->toBe(1)
        ->and(TelemetryRows::rollups('rollups_hour')[0]['bucket'])->toBe('2026-11-03T12:00:00+00:00');
});

it('keeps different environments in separate rows', function (): void {
    TelemetryRows::request($this->hour->addMinutes(5));
    TelemetryRows::request($this->hour->addMinutes(5), ['environment_id' => 2]);

    expect($this->builder->handle())->toBe(2)
        ->and(array_column(TelemetryRows::rollups('rollups_hour'), 'environment_id'))->toBe([1, 2]);
});

it('does not write minute rollups', function (): void {
    TelemetryRows::request($this->hour->addMinutes(5));

    $this->builder->handle();

    expect(DB::connection('app_watch')->table('rollups_minute')->count())->toBe(0);
});
