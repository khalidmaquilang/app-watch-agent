<?php

declare(strict_types=1);

use AppWatch\Agent\Recorder;
use AppWatch\Agent\Support\TraceContext;
use AppWatch\Agent\Tests\Support\TelemetryRows;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    config(['app-watch.rollups.grace_minutes' => 2]);
    $this->minute = CarbonImmutable::parse('2026-11-03 12:00:00', 'UTC');
    CarbonImmutable::setTestNow($this->minute->addSeconds(90));
    installTelemetrySchema();
});

afterEach(function (): void {
    dropTelemetrySchema();
});

/**
 * Seed 50 requests per environment (1 and 2) for one route in the minute: environment 1 takes 1 … 50 ms
 * (the first two return 500), environment 2 takes 1000 … 50000 ms (all 200).
 */
function seedTwoEnvironmentMinute(CarbonImmutable $minute): void
{
    foreach (range(1, 50) as $index) {
        TelemetryRows::request($minute->addMilliseconds($index * 1000), ['environment_id' => 1, 'duration_ms' => $index, 'status_code' => $index <= 2 ? 500 : 200]);
        TelemetryRows::request($minute->addMilliseconds($index * 1000 + 1), ['environment_id' => 2, 'duration_ms' => $index * 1000]);
    }
}

it('builds per-environment minute rollups from 100 requests in one minute', function (): void {
    seedTwoEnvironmentMinute($this->minute);

    $this->artisan('app-watch:rollups', ['kind' => 'minute'])
        ->expectsOutputToContain('Upserted 2 minute rollup bucket(s).')
        ->assertExitCode(0);

    $rows = collect(TelemetryRows::rollups('rollups_minute'))->keyBy('environment_id');

    expect($rows)->toHaveCount(2)
        ->and($rows[1]['key'])->toBe('GET users/{user}')
        ->and($rows[1]['bucket'])->toBe('2026-11-03T12:00:00+00:00')
        ->and($rows[1]['count'])->toBe(50)
        ->and($rows[1]['error_count'])->toBe(2)
        ->and((float) $rows[1]['p50'])->toEqualWithDelta(25.5, 0.01)
        ->and((float) $rows[1]['p95'])->toEqualWithDelta(47.55, 0.01)
        ->and((float) $rows[1]['p99'])->toEqualWithDelta(49.51, 0.01)
        ->and((float) $rows[1]['max'])->toEqualWithDelta(50, 0.01)
        ->and($rows[2]['count'])->toBe(50)
        ->and($rows[2]['error_count'])->toBe(0)
        ->and((float) $rows[2]['p50'])->toEqualWithDelta(25500, 0.01)
        ->and((float) $rows[2]['p95'])->toEqualWithDelta(47550, 0.01)
        ->and((float) $rows[2]['p99'])->toEqualWithDelta(49510, 0.01)
        ->and((float) $rows[2]['max'])->toEqualWithDelta(50000, 0.01);
});

it('yields the same rows when minute runs again', function (): void {
    seedTwoEnvironmentMinute($this->minute);

    $this->artisan('app-watch:rollups', ['kind' => 'minute'])->assertExitCode(0);
    $first_run = TelemetryRows::rollups('rollups_minute');

    $this->artisan('app-watch:rollups', ['kind' => 'minute'])->assertExitCode(0);

    expect(TelemetryRows::rollups('rollups_minute'))->toEqual($first_run)
        ->and($first_run)->toHaveCount(2);
});

it('counts an event that arrives late for the previous minute on the next run', function (): void {
    TelemetryRows::request($this->minute->addSeconds(10), ['duration_ms' => 10]);
    $this->artisan('app-watch:rollups', ['kind' => 'minute'])->assertExitCode(0);

    CarbonImmutable::setTestNow($this->minute->addMinutes(2)->addSeconds(5));
    TelemetryRows::request($this->minute->addSeconds(55), ['duration_ms' => 40]);
    $this->artisan('app-watch:rollups', ['kind' => 'minute'])->assertExitCode(0);

    $rows = TelemetryRows::rollups('rollups_minute');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['count'])->toBe(2)
        ->and((float) $rows[0]['max'])->toEqualWithDelta(40, 0.01);
});

it('builds per-environment hour rollups from the raw rows', function (): void {
    seedTwoEnvironmentMinute($this->minute);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-03 13:02:00', 'UTC'));

    $this->artisan('app-watch:rollups', ['kind' => 'hour'])
        ->expectsOutputToContain('Upserted 2 hour rollup bucket(s).')
        ->assertExitCode(0);

    $rows = collect(TelemetryRows::rollups('rollups_hour'))->keyBy('environment_id');

    expect($rows)->toHaveCount(2)
        ->and($rows[1]['bucket'])->toBe('2026-11-03T12:00:00+00:00')
        ->and($rows[1]['count'])->toBe(50)
        ->and($rows[1]['error_count'])->toBe(2)
        ->and((float) $rows[1]['p95'])->toEqualWithDelta(47.55, 0.01)
        ->and((float) $rows[2]['max'])->toEqualWithDelta(50000, 0.01)
        ->and(DB::connection('app_watch')->table('rollups_minute')->count())->toBe(0);
});

it('upserts nothing and succeeds when the raw tables are empty', function (string $kind): void {
    $this->artisan('app-watch:rollups', ['kind' => $kind])
        ->expectsOutputToContain("Upserted 0 {$kind} rollup bucket(s).")
        ->assertExitCode(0);
})->with(['minute', 'hour']);

it('prunes only the minute and hour rollups past their retention', function (): void {
    $now = CarbonImmutable::now('UTC');
    $kept_minute = TelemetryRows::rollup('rollups_minute', $now->subDays(6)->subHours(23));
    TelemetryRows::rollup('rollups_minute', $now->subDays(7)->subHour());
    $kept_hour = TelemetryRows::rollup('rollups_hour', $now->subDays(89)->subHours(23));
    TelemetryRows::rollup('rollups_hour', $now->subDays(90)->subHour());

    $this->artisan('app-watch:rollups', ['kind' => 'prune'])
        ->expectsOutputToContain('Pruned 1 minute rollup(s) and 1 hour rollup(s).')
        ->assertExitCode(0);

    expect(DB::connection('app_watch')->table('rollups_minute')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all())->toBe([$kept_minute])
        ->and(DB::connection('app_watch')->table('rollups_hour')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all())->toBe([$kept_hour]);
});

it('fails without deleting anything when a retention is below 1', function (): void {
    config(['app-watch.rollups.minute_retention_days' => 0]);
    TelemetryRows::rollup('rollups_minute', CarbonImmutable::now('UTC')->subDays(30));

    $this->artisan('app-watch:rollups', ['kind' => 'prune'])
        ->expectsOutputToContain('app-watch.rollups.minute_retention_days must be at least 1.')
        ->assertExitCode(1);

    expect(DB::connection('app_watch')->table('rollups_minute')->count())->toBe(1);
});

it('fails on an unknown kind', function (): void {
    TelemetryRows::request($this->minute->addSeconds(10));

    $this->artisan('app-watch:rollups', ['kind' => 'bogus'])
        ->expectsOutputToContain('Invalid rollup kind [bogus]. Use one of: minute, hour, prune.')
        ->assertExitCode(1);

    expect(DB::connection('app_watch')->table('rollups_minute')->count())->toBe(0);
});

it('runs on the --database connection', function (): void {
    config([
        'database.connections.client' => config('database.connections.app_watch'),
        'app-watch.connection' => 'missing_connection',
    ]);
    TelemetryRows::request($this->minute->addSeconds(10));

    $this->artisan('app-watch:rollups', ['kind' => 'minute', '--database' => 'client'])
        ->expectsOutputToContain('Upserted 1 minute rollup bucket(s).')
        ->assertExitCode(0);
});

it('fails without changing anything when the connection does not exist', function (): void {
    TelemetryRows::request($this->minute->addSeconds(10));

    $this->artisan('app-watch:rollups', ['kind' => 'minute', '--database' => 'missing_connection'])
        ->expectsOutputToContain('Database connection [missing_connection] is not configured')
        ->assertExitCode(1);

    expect(DB::connection('app_watch')->table('rollups_minute')->count())->toBe(0);
});

it('records none of its own queries, exceptions or logs', function (string $kind): void {
    seedTwoEnvironmentMinute($this->minute);
    $trace_context = app(TraceContext::class);
    $trace_context->end();

    $this->artisan('app-watch:rollups', ['kind' => $kind])->assertExitCode(0);

    expect($trace_context->traceId())->toBeNull();

    $trace_id = (string) $trace_context->start();

    $this->artisan('app-watch:rollups', ['kind' => $kind])->assertExitCode(0);

    expect($trace_context->traceId())->toBe($trace_id)
        ->and(app(Recorder::class)->flush($trace_id))->toBe([]);

    $trace_context->end();
})->with(['minute', 'hour', 'prune']);
