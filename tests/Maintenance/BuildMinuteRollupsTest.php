<?php

declare(strict_types=1);

use AppWatch\Agent\Maintenance\BuildMinuteRollups;
use AppWatch\Agent\Maintenance\BuildRollupsForRange;
use AppWatch\Agent\Tests\Support\TelemetryRows;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    config(['app-watch.rollups.grace_minutes' => 2]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-03 12:01:30', 'UTC'));
    installTelemetrySchema();

    $this->builder = app(BuildMinuteRollups::class);
    $this->minute = CarbonImmutable::parse('2026-11-03 12:00:00', 'UTC');
});

afterEach(function (): void {
    dropTelemetrySchema();
});

/**
 * The buckets of the minute rollups as `H:i`, oldest first.
 *
 * @return list<string>
 */
function minuteRollupBuckets(): array
{
    return array_map(
        static fn (array $rollup): string => CarbonImmutable::parse($rollup['bucket'])->format('H:i'),
        TelemetryRows::rollups('rollups_minute'),
    );
}

it('rolls up the last closed minute with Postgres percentiles', function (): void {
    foreach (range(1, 100) as $duration) {
        TelemetryRows::request($this->minute->addMilliseconds($duration * 500), [
            'duration_ms' => $duration,
            'status_code' => $duration <= 3 ? 500 : 200,
        ]);
    }

    expect($this->builder->handle())->toBe(1);

    $rows = TelemetryRows::rollups('rollups_minute');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['bucket'])->toBe('2026-11-03T12:00:00+00:00')
        ->and($rows[0]['key'])->toBe('GET users/{user}')
        ->and($rows[0]['count'])->toBe(100)
        ->and($rows[0]['error_count'])->toBe(3)
        ->and((float) $rows[0]['duration_sum'])->toEqualWithDelta(5050, 0.01)
        ->and((float) $rows[0]['p50'])->toEqualWithDelta(50.5, 0.01)
        ->and((float) $rows[0]['p95'])->toEqualWithDelta(95.05, 0.01)
        ->and((float) $rows[0]['p99'])->toEqualWithDelta(99.01, 0.01)
        ->and((float) $rows[0]['max'])->toEqualWithDelta(100, 0.01);
});

it('rolls up command runs per command with failures as errors, idempotently', function (): void {
    foreach ([100, 200, 300] as $index => $duration) {
        TelemetryRows::commandRun($this->minute->addSeconds($index + 1), ['command' => 'a:b', 'duration_ms' => $duration, 'failed' => $duration === 200, 'exit_code' => $duration === 200 ? 1 : 0]);
    }

    $now = CarbonImmutable::parse('2026-11-03 12:01:00', 'UTC');

    expect($this->builder->handle($now))->toBe(1);

    $rows = TelemetryRows::rollups('rollups_minute');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['type'])->toBe('command')
        ->and($rows[0]['key'])->toBe('a:b')
        ->and($rows[0]['bucket'])->toBe('2026-11-03T12:00:00+00:00')
        ->and($rows[0]['count'])->toBe(3)
        ->and($rows[0]['error_count'])->toBe(1)
        ->and((float) $rows[0]['duration_sum'])->toEqualWithDelta(600, 0.01)
        ->and((float) $rows[0]['p95'])->toEqualWithDelta(290, 0.01)
        ->and((float) $rows[0]['max'])->toEqualWithDelta(300, 0.01);

    $this->builder->handle($now);

    expect(TelemetryRows::rollups('rollups_minute'))->toEqual($rows);
});

it('never rolls up the current open minute', function (): void {
    TelemetryRows::request(CarbonImmutable::parse('2026-11-03 12:01:00', 'UTC'));
    TelemetryRows::request(CarbonImmutable::parse('2026-11-03 12:01:29', 'UTC'));

    expect($this->builder->handle())->toBe(0)
        ->and(DB::connection('app_watch')->table('rollups_minute')->count())->toBe(0);
});

it('yields identical rows when run twice for the same now', function (): void {
    foreach (range(1, 20) as $duration) {
        TelemetryRows::request($this->minute->addSeconds($duration), ['duration_ms' => $duration]);
    }

    $now = CarbonImmutable::parse('2026-11-03 12:01:00', 'UTC');

    $this->builder->handle($now);
    $first_run = TelemetryRows::rollups('rollups_minute');

    $this->builder->handle($now);

    expect(TelemetryRows::rollups('rollups_minute'))->toEqual($first_run)
        ->and($first_run)->toHaveCount(1);
});

it('re-aggregates a late event inside the grace window', function (): void {
    TelemetryRows::request($this->minute->addSeconds(10), ['duration_ms' => 10]);
    $this->builder->handle(CarbonImmutable::parse('2026-11-03 12:01:00', 'UTC'));

    TelemetryRows::request($this->minute->addSeconds(50), ['duration_ms' => 30]);
    $this->builder->handle(CarbonImmutable::parse('2026-11-03 12:02:00', 'UTC'));

    $rows = TelemetryRows::rollups('rollups_minute');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['count'])->toBe(2)
        ->and((float) $rows[0]['duration_sum'])->toEqualWithDelta(40, 0.01)
        ->and((float) $rows[0]['max'])->toEqualWithDelta(30, 0.01);
});

it('does not re-aggregate a late event outside the grace window', function (): void {
    TelemetryRows::request($this->minute->addSeconds(10), ['duration_ms' => 10]);
    $this->builder->handle(CarbonImmutable::parse('2026-11-03 12:01:00', 'UTC'));

    TelemetryRows::request($this->minute->addSeconds(50), ['duration_ms' => 30]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-03 12:05:10', 'UTC'));
    $this->builder->handle();

    $rows = TelemetryRows::rollups('rollups_minute');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['count'])->toBe(1)
        ->and((float) $rows[0]['duration_sum'])->toEqualWithDelta(10, 0.01);
});

it('covers exactly grace_minutes closed minutes', function (): void {
    config(['app-watch.rollups.grace_minutes' => 3]);

    foreach (['11:56', '11:57', '11:58', '11:59', '12:00'] as $time) {
        TelemetryRows::request(CarbonImmutable::parse("2026-11-03 {$time}:30", 'UTC'));
    }

    $this->builder->handle(CarbonImmutable::parse('2026-11-03 12:00:45', 'UTC'));

    expect(minuteRollupBuckets())->toBe(['11:57', '11:58', '11:59']);
});

it('still builds the last closed minute when grace_minutes is 0', function (): void {
    config(['app-watch.rollups.grace_minutes' => 0]);
    TelemetryRows::request($this->minute->addSeconds(5));

    expect($this->builder->handle())->toBe(1);
});

it('floors now to the UTC minute whatever its timezone', function (): void {
    TelemetryRows::request($this->minute->addSeconds(5));

    expect($this->builder->handle(CarbonImmutable::parse('2026-11-03 20:01:59', 'Asia/Manila')))->toBe(1)
        ->and(TelemetryRows::rollups('rollups_minute')[0]['bucket'])->toBe('2026-11-03T12:00:00+00:00');
});

it('keeps two environments with the same route in two rows', function (): void {
    TelemetryRows::request($this->minute->addSeconds(5));
    TelemetryRows::request($this->minute->addSeconds(5), ['environment_id' => 2]);

    expect($this->builder->handle())->toBe(2)
        ->and(DB::connection('app_watch')->table('rollups_minute')->where('key', 'GET users/{user}')->count())->toBe(2);
});

it('runs one SQL statement per rolled-up type', function (): void {
    TelemetryRows::request($this->minute->addSeconds(5));

    DB::connection('app_watch')->enableQueryLog();
    $this->builder->handle();

    expect(DB::connection('app_watch')->getQueryLog())->toHaveCount(count(BuildRollupsForRange::TYPES));
});
