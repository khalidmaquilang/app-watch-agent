<?php

declare(strict_types=1);

use AppWatch\Agent\Maintenance\PruneRollups;
use AppWatch\Agent\Tests\Support\TelemetryRows;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    config(['app-watch.rollups.minute_retention_days' => 7, 'app-watch.rollups.hour_retention_days' => 90]);
    $this->now = CarbonImmutable::parse('2026-11-03 13:30:00', 'UTC');
    CarbonImmutable::setTestNow($this->now);
    installTelemetrySchema();

    $this->pruner = app(PruneRollups::class);
});

afterEach(function (): void {
    dropTelemetrySchema();
});

/**
 * The ids left in the rollup table, ascending.
 *
 * @return list<int>
 */
function remainingRollupIds(string $table): array
{
    /** @var list<int> $ids */
    $ids = DB::connection('app_watch')->table($table)->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

    return $ids;
}

it('deletes minute rollups strictly older than the 7 day retention and keeps the exact boundary', function (): void {
    TelemetryRows::rollup('rollups_minute', $this->now->subDays(8));
    $boundary = TelemetryRows::rollup('rollups_minute', $this->now->subDays(7));
    $recent = TelemetryRows::rollup('rollups_minute', $this->now->subDays(6));

    $result = $this->pruner->handle();

    expect($result->minute_deleted)->toBe(1)
        ->and($result->hour_deleted)->toBe(0)
        ->and(remainingRollupIds('rollups_minute'))->toBe([$boundary, $recent]);
});

it('keeps a minute rollup 6 d 23 h old and deletes one 7 d 1 h old', function (): void {
    $kept = TelemetryRows::rollup('rollups_minute', $this->now->subDays(6)->subHours(23));
    TelemetryRows::rollup('rollups_minute', $this->now->subDays(7)->subHour());

    expect($this->pruner->handle()->minute_deleted)->toBe(1)
        ->and(remainingRollupIds('rollups_minute'))->toBe([$kept]);
});

it('keeps a minute rollup one second newer than the cutoff and deletes one a second older', function (): void {
    TelemetryRows::rollup('rollups_minute', $this->now->subDays(7)->subSecond());
    $newer = TelemetryRows::rollup('rollups_minute', $this->now->subDays(7)->addSecond());

    expect($this->pruner->handle()->minute_deleted)->toBe(1)
        ->and(remainingRollupIds('rollups_minute'))->toBe([$newer]);
});

it('deletes hour rollups older than 90 days and keeps newer ones', function (): void {
    TelemetryRows::rollup('rollups_hour', $this->now->subDays(120));
    TelemetryRows::rollup('rollups_hour', $this->now->subDays(90)->subHour());
    $boundary = TelemetryRows::rollup('rollups_hour', $this->now->subDays(90));
    $inside = TelemetryRows::rollup('rollups_hour', $this->now->subDays(89)->subHours(23));
    $minute = TelemetryRows::rollup('rollups_minute', $this->now->subDay());

    $result = $this->pruner->handle();

    expect($result->hour_deleted)->toBe(2)
        ->and($result->minute_deleted)->toBe(0)
        ->and(remainingRollupIds('rollups_hour'))->toBe([$boundary, $inside])
        ->and(remainingRollupIds('rollups_minute'))->toBe([$minute]);
});

it('respects retention overrides from config', function (): void {
    config(['app-watch.rollups.minute_retention_days' => 1, 'app-watch.rollups.hour_retention_days' => 10]);

    TelemetryRows::rollup('rollups_minute', $this->now->subDays(2));
    $kept_minute = TelemetryRows::rollup('rollups_minute', $this->now->subHours(12));
    TelemetryRows::rollup('rollups_hour', $this->now->subDays(11));
    $kept_hour = TelemetryRows::rollup('rollups_hour', $this->now->subDays(9));

    $result = $this->pruner->handle();

    expect($result->minute_deleted)->toBe(1)
        ->and($result->hour_deleted)->toBe(1)
        ->and(remainingRollupIds('rollups_minute'))->toBe([$kept_minute])
        ->and(remainingRollupIds('rollups_hour'))->toBe([$kept_hour]);
});

it('uses the given now instead of the current time', function (): void {
    TelemetryRows::rollup('rollups_minute', $this->now->subDays(6));

    expect($this->pruner->handle($this->now->addDays(2))->minute_deleted)->toBe(1)
        ->and(remainingRollupIds('rollups_minute'))->toBe([]);
});

it('deletes rollups of every environment and type', function (): void {
    TelemetryRows::rollup('rollups_minute', $this->now->subDays(10), ['environment_id' => 2, 'type' => 'cache', 'key' => 'users']);
    TelemetryRows::rollup('rollups_minute', $this->now->subDays(10), ['type' => 'job', 'key' => 'App\\Jobs\\SendMailJob']);

    expect($this->pruner->handle()->minute_deleted)->toBe(2)
        ->and(remainingRollupIds('rollups_minute'))->toBe([]);
});

it('deletes 25 000 expired rows across multiple chunks and reports the correct counts', function (): void {
    $bucket = $this->now->subDays(30)->startOfMinute()->format('Y-m-d H:i:s.uP');
    $rows = array_map(static fn (int $index): array => [
        'environment_id' => 1,
        'type' => 'request',
        'key' => 'GET route-'.$index,
        'bucket' => $bucket,
        'count' => 1,
        'error_count' => 0,
    ], range(1, 25000));

    foreach (array_chunk($rows, 2500) as $chunk) {
        DB::connection('app_watch')->table('rollups_minute')->insert($chunk);
    }

    $kept = TelemetryRows::rollup('rollups_minute', $this->now->subDay());

    $delete_statements = 0;
    DB::connection('app_watch')->listen(function (QueryExecuted $query) use (&$delete_statements): void {
        if (str_starts_with(mb_strtolower(mb_ltrim($query->sql)), 'delete from "rollups_minute"')) {
            $delete_statements++;
        }
    });

    $result = $this->pruner->handle();

    expect($result->minute_deleted)->toBe(25000)
        ->and($result->hour_deleted)->toBe(0)
        ->and($delete_statements)->toBe(3)
        ->and(remainingRollupIds('rollups_minute'))->toBe([$kept]);
});

it('is idempotent', function (): void {
    TelemetryRows::rollup('rollups_minute', $this->now->subDays(8));
    TelemetryRows::rollup('rollups_hour', $this->now->subDays(100));

    $first = $this->pruner->handle();
    $second = $this->pruner->handle();

    expect($first->minute_deleted)->toBe(1)
        ->and($first->hour_deleted)->toBe(1)
        ->and($second->minute_deleted)->toBe(0)
        ->and($second->hour_deleted)->toBe(0);
});

it('returns zero counts when there is nothing to prune', function (): void {
    $result = $this->pruner->handle();

    expect($result->minute_deleted)->toBe(0)
        ->and($result->hour_deleted)->toBe(0);
});

it('rejects a retention below 1 day without deleting anything', function (string $config_key, int $retention_days): void {
    config([$config_key => $retention_days]);
    TelemetryRows::rollup('rollups_minute', $this->now->subDays(30));
    TelemetryRows::rollup('rollups_hour', $this->now->subDays(365));

    expect(fn () => $this->pruner->handle())->toThrow(InvalidArgumentException::class, $config_key)
        ->and(DB::connection('app_watch')->table('rollups_minute')->count())->toBe(1)
        ->and(DB::connection('app_watch')->table('rollups_hour')->count())->toBe(1);
})->with([
    'minute retention 0' => ['app-watch.rollups.minute_retention_days', 0],
    'minute retention negative' => ['app-watch.rollups.minute_retention_days', -1],
    'hour retention 0' => ['app-watch.rollups.hour_retention_days', 0],
    'hour retention negative' => ['app-watch.rollups.hour_retention_days', -5],
]);
