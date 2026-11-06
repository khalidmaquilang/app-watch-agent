<?php

declare(strict_types=1);

use AppWatch\Agent\Maintenance\BuildRollupsForRange;
use AppWatch\Agent\Tests\Support\TelemetryRows;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-03 12:30:00', 'UTC'));
    installTelemetrySchema();

    $this->builder = app(BuildRollupsForRange::class);
    $this->from = CarbonImmutable::parse('2026-11-03 12:00:00', 'UTC');
    $this->to = CarbonImmutable::parse('2026-11-03 12:01:00', 'UTC');
});

afterEach(function (): void {
    dropTelemetrySchema();
});

it('aggregates 100 requests of one route into one row with Postgres percentiles', function (): void {
    foreach (range(1, 100) as $duration) {
        TelemetryRows::request($this->from->addMilliseconds($duration * 100), [
            'status_code' => $duration <= 3 ? 500 : 200,
            'duration_ms' => $duration,
        ]);
    }

    expect($this->builder->handle('rollups_minute', $this->from, $this->to, 'minute'))->toBe(1);

    $rollup = DB::connection('app_watch')->table('rollups_minute')->sole();

    expect($rollup->environment_id)->toBe(1)
        ->and($rollup->type)->toBe('request')
        ->and($rollup->key)->toBe('GET users/{user}')
        ->and(CarbonImmutable::parse($rollup->bucket)->utc()->toIso8601String())->toBe('2026-11-03T12:00:00+00:00')
        ->and($rollup->count)->toBe(100)
        ->and($rollup->error_count)->toBe(3)
        ->and((float) $rollup->duration_sum)->toEqualWithDelta(5050, 0.01)
        ->and((float) $rollup->p50)->toEqualWithDelta(50.5, 0.01)
        ->and((float) $rollup->p95)->toEqualWithDelta(95.05, 0.01)
        ->and((float) $rollup->p99)->toEqualWithDelta(99.01, 0.01)
        ->and((float) $rollup->max)->toEqualWithDelta(100, 0.01);
});

it('uses the per-type key and error rules', function (): void {
    $recorded_at = $this->from->addSeconds(5);

    TelemetryRows::raw('requests', ['recorded_at' => $recorded_at, 'method' => 'POST', 'route' => null, 'status_code' => 404]);
    TelemetryRows::raw('queries', ['recorded_at' => $recorded_at, 'sql_hash' => str_repeat('a', 40)]);
    TelemetryRows::raw('job_runs', ['recorded_at' => $recorded_at, 'status' => 'failed']);
    TelemetryRows::raw('job_runs', ['recorded_at' => $recorded_at, 'status' => 'processed']);
    TelemetryRows::raw('outgoing_requests', ['recorded_at' => $recorded_at, 'failed' => true]);
    TelemetryRows::raw('cache_events', ['recorded_at' => $recorded_at, 'key' => 'users:1', 'operation' => 'miss', 'duration_ms' => 3.0]);
    TelemetryRows::raw('cache_events', ['recorded_at' => $recorded_at, 'key' => 'users:2', 'operation' => 'hit', 'duration_ms' => 1.0]);
    TelemetryRows::raw('cache_events', ['recorded_at' => $recorded_at, 'key' => 'settings', 'operation' => 'hit']);
    TelemetryRows::commandRun($recorded_at, ['command' => 'orders:sync', 'exit_code' => 1, 'failed' => true]);
    TelemetryRows::commandRun($recorded_at, ['command' => 'orders:sync']);
    TelemetryRows::scheduledTaskRun($recorded_at, ['task' => 'emails:send', 'outcome' => 'failed', 'exit_code' => 1]);
    TelemetryRows::scheduledTaskRun($recorded_at, ['task' => 'emails:send', 'outcome' => 'skipped', 'duration_ms' => 0]);
    TelemetryRows::scheduledTaskRun($recorded_at, ['task' => 'emails:send']);

    expect($this->builder->handle('rollups_minute', $this->from, $this->to, 'minute'))->toBe(8);

    $rows = collect(TelemetryRows::rollups('rollups_minute'))
        ->mapWithKeys(fn (array $rollup): array => ["{$rollup['type']}|{$rollup['key']}" => [$rollup['count'], $rollup['error_count']]])
        ->all();

    expect($rows)->toEqual([
        'request|POST [unmatched]' => [1, 0],
        'query|'.str_repeat('a', 40) => [1, 0],
        'job|App\\Jobs\\SendMailJob' => [2, 1],
        'outgoing_request|api.stripe.com' => [1, 1],
        'cache|users' => [2, 1],
        'cache|settings' => [1, 0],
        'command|orders:sync' => [2, 1],
        'scheduled_task|emails:send' => [2, 1],
    ]);
});

it('leaves the duration statistics of cache rollups null', function (): void {
    TelemetryRows::raw('cache_events', ['recorded_at' => $this->from, 'duration_ms' => 4.0]);

    $this->builder->handle('rollups_minute', $this->from, $this->to, 'minute');

    $rollup = DB::connection('app_watch')->table('rollups_minute')->sole();

    expect($rollup->duration_sum)->toBeNull()
        ->and($rollup->p50)->toBeNull()
        ->and($rollup->p95)->toBeNull()
        ->and($rollup->p99)->toBeNull()
        ->and($rollup->max)->toBeNull();
});

it('does not roll up logs', function (): void {
    TelemetryRows::raw('logs', ['recorded_at' => $this->from]);

    expect($this->builder->handle('rollups_minute', $this->from, $this->to, 'minute'))->toBe(0)
        ->and(DB::connection('app_watch')->table('rollups_minute')->count())->toBe(0);
});

it('upserts nothing when the raw tables are empty', function (): void {
    expect($this->builder->handle('rollups_minute', $this->from, $this->to, 'minute'))->toBe(0)
        ->and(DB::connection('app_watch')->table('rollups_minute')->count())->toBe(0);
});

it('includes from and excludes to', function (): void {
    TelemetryRows::request($this->from);
    TelemetryRows::request($this->to);
    TelemetryRows::request($this->from->subMicrosecond());

    $this->builder->handle('rollups_minute', $this->from, $this->to, 'minute');

    expect(TelemetryRows::rollups('rollups_minute'))->toHaveCount(1)
        ->and(TelemetryRows::rollups('rollups_minute')[0]['bucket'])->toBe('2026-11-03T12:00:00+00:00')
        ->and(TelemetryRows::rollups('rollups_minute')[0]['count'])->toBe(1);
});

it('produces one row per minute that has events and none for empty minutes', function (): void {
    TelemetryRows::request($this->from);
    TelemetryRows::request($this->from->addMinutes(2));

    $this->builder->handle('rollups_minute', $this->from, $this->from->addMinutes(3), 'minute');

    expect(array_column(TelemetryRows::rollups('rollups_minute'), 'bucket'))
        ->toBe(['2026-11-03T12:00:00+00:00', '2026-11-03T12:02:00+00:00']);
});

it('aggregates every environment present in the raw tables into its own rows', function (): void {
    foreach ([1 => 10.0, 2 => 500.0] as $environment_id => $duration) {
        TelemetryRows::request($this->from, ['environment_id' => $environment_id, 'duration_ms' => $duration]);
    }

    expect($this->builder->handle('rollups_minute', $this->from, $this->to, 'minute'))->toBe(2);

    $rows = TelemetryRows::rollups('rollups_minute');

    expect(array_column($rows, 'environment_id'))->toBe([1, 2])
        ->and((float) $rows[0]['max'])->toEqualWithDelta(10, 0.01)
        ->and((float) $rows[1]['max'])->toEqualWithDelta(500, 0.01);
});

it('builds hour buckets into the hour table', function (): void {
    TelemetryRows::request($this->from->addMinutes(5), ['duration_ms' => 10]);
    TelemetryRows::request($this->from->addMinutes(25), ['duration_ms' => 20]);

    $this->builder->handle('rollups_hour', $this->from, $this->from->addHour(), 'hour');

    $rows = TelemetryRows::rollups('rollups_hour');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['bucket'])->toBe('2026-11-03T12:00:00+00:00')
        ->and($rows[0]['count'])->toBe(2)
        ->and((float) $rows[0]['duration_sum'])->toEqualWithDelta(30, 0.01)
        ->and(DB::connection('app_watch')->table('rollups_minute')->count())->toBe(0);
});

it('truncates keys longer than 512 characters to the same row', function (): void {
    $long_route = str_repeat('a', 600);

    TelemetryRows::request($this->from, ['route' => $long_route.'1']);
    TelemetryRows::request($this->from, ['route' => $long_route.'2']);

    $this->builder->handle('rollups_minute', $this->from, $this->to, 'minute');

    $rows = TelemetryRows::rollups('rollups_minute');

    expect($rows)->toHaveCount(1)
        ->and(mb_strlen($rows[0]['key']))->toBe(BuildRollupsForRange::KEY_MAX_LENGTH)
        ->and($rows[0]['count'])->toBe(2);
});

it('overwrites an existing row with the recomputed aggregates', function (): void {
    TelemetryRows::request($this->from, ['duration_ms' => 7]);
    TelemetryRows::rollup('rollups_minute', $this->from, ['count' => 999, 'error_count' => 50]);

    expect($this->builder->handle('rollups_minute', $this->from, $this->to, 'minute'))->toBe(1);

    $rows = TelemetryRows::rollups('rollups_minute');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['count'])->toBe(1)
        ->and($rows[0]['error_count'])->toBe(0)
        ->and((float) $rows[0]['max'])->toEqualWithDelta(7, 0.01);
});

it('runs one SQL statement per rolled-up type', function (): void {
    TelemetryRows::request($this->from);
    TelemetryRows::request($this->from, ['environment_id' => 2]);

    DB::connection('app_watch')->enableQueryLog();
    $this->builder->handle('rollups_minute', $this->from, $this->to, 'minute');

    expect(DB::connection('app_watch')->getQueryLog())->toHaveCount(count(BuildRollupsForRange::TYPES));
});

it('runs on the given connection', function (): void {
    config(['database.connections.client' => config('database.connections.app_watch'), 'app-watch.connection' => 'missing_connection']);
    TelemetryRows::request($this->from);

    expect($this->builder->handle('rollups_minute', $this->from, $this->to, 'minute', 'client'))->toBe(1);
});

it('rejects a rollup table that is not allowed', function (): void {
    $this->builder->handle('users', $this->from, $this->to, 'minute');
})->throws(InvalidArgumentException::class, 'Invalid rollup table [users].');

it('rejects a bucket unit that is not allowed', function (): void {
    $this->builder->handle('rollups_minute', $this->from, $this->to, "minute', recorded_at); --");
})->throws(InvalidArgumentException::class, 'Invalid bucket unit');

it('rejects a range whose end is not after its start', function (): void {
    $this->builder->handle('rollups_minute', $this->to, $this->from, 'minute');
})->throws(InvalidArgumentException::class, 'to must be after from.');
