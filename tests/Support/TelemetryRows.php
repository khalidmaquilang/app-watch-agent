<?php

declare(strict_types=1);

namespace AppWatch\Agent\Tests\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Plain inserts of raw telemetry and rollup rows on the `app_watch` connection (the package has no factories).
 */
final class TelemetryRows
{
    /**
     * Insert one raw row into the given raw table: the common columns, the type's defaults, then the overrides.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function raw(string $table, array $overrides = []): void
    {
        $defaults = match ($table) {
            'requests' => ['method' => 'GET', 'route' => 'users/{user}', 'path' => '/users/1', 'status_code' => 200, 'duration_ms' => 10.0],
            'queries' => ['connection' => 'pgsql', 'sql' => 'select 1', 'sql_hash' => sha1('select 1'), 'duration_ms' => 1.0],
            'job_runs' => ['job_class' => 'App\\Jobs\\SendMailJob', 'queue' => 'default', 'connection' => 'sync', 'status' => 'processed', 'attempt' => 1, 'duration_ms' => 5.0],
            'outgoing_requests' => ['method' => 'GET', 'target_host' => 'api.stripe.com', 'url' => 'https://api.stripe.com/v1', 'status_code' => 200, 'failed' => false, 'duration_ms' => 20.0],
            'cache_events' => ['store' => 'redis', 'key' => 'users:1', 'operation' => 'hit', 'duration_ms' => null],
            'logs' => ['level' => 'error', 'message' => 'Something happened', 'context' => null],
            default => [],
        };

        $row = [
            'environment_id' => 1,
            'trace_id' => (string) Str::uuid(),
            'recorded_at' => now('UTC'),
            'host' => 'web-1',
            ...$defaults,
            ...$overrides,
        ];

        if ($row['recorded_at'] instanceof DateTimeInterface) {
            $row['recorded_at'] = $row['recorded_at']->format('Y-m-d H:i:s.uP');
        }

        DB::connection('app_watch')->table($table)->insert($row);
    }

    /**
     * The number of rows of the given table stored for the given environment.
     */
    public static function countFor(string $table, int $environment_id): int
    {
        return DB::connection('app_watch')->table($table)->where('environment_id', $environment_id)->count();
    }

    /**
     * The name of the partition (`tableoid::regclass`) the row with the given id of a raw table is stored in.
     */
    public static function partitionOf(string $table, int $id): string
    {
        return (string) DB::connection('app_watch')->table($table)->where('id', $id)->value(DB::raw('tableoid::regclass::text'));
    }

    /**
     * Insert one request row recorded at the given time.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function request(DateTimeInterface $recorded_at, array $overrides = []): void
    {
        self::raw('requests', ['recorded_at' => $recorded_at, ...$overrides]);
    }

    /**
     * Insert one rollup row into `rollups_minute` or `rollups_hour` and return its id.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function rollup(string $table, DateTimeInterface $bucket, array $overrides = []): int
    {
        return (int) DB::connection('app_watch')->table($table)->insertGetId([
            'environment_id' => 1,
            'type' => 'request',
            'key' => 'GET users/{user}',
            'count' => 1,
            'error_count' => 0,
            ...$overrides,
            'bucket' => $bucket->format('Y-m-d H:i:s.uP'),
        ]);
    }

    /**
     * Every row of the rollup table without its id, ordered by environment, type, key and bucket, with
     * the bucket as an ISO 8601 UTC string.
     *
     * @return list<array<string, mixed>>
     */
    public static function rollups(string $table): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = DB::connection('app_watch')->table($table)
            ->orderBy('environment_id')->orderBy('type')->orderBy('key')->orderBy('bucket')
            ->get()
            ->map(function (object $row): array {
                $values = (array) $row;
                unset($values['id']);
                $values['bucket'] = CarbonImmutable::parse((string) $values['bucket'])->utc()->toIso8601String();

                return $values;
            })
            ->values()
            ->all();

        return $rows;
    }
}
