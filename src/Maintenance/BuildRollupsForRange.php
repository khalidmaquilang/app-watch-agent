<?php

declare(strict_types=1);

namespace AppWatch\Agent\Maintenance;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;

/**
 * Aggregates the raw telemetry of a time range into minute or hour rollup buckets in the client's database.
 */
final class BuildRollupsForRange
{
    /**
     * The rollup tables this class may write to.
     *
     * @var list<string>
     */
    public const array ROLLUP_TABLES = ['rollups_minute', 'rollups_hour'];

    /**
     * The `date_trunc` units a bucket may have.
     *
     * @var list<string>
     */
    public const array BUCKET_UNITS = ['minute', 'hour'];

    /**
     * The length of the rollup `key` column; longer keys are truncated, so keys sharing their first
     * KEY_MAX_LENGTH characters collapse into one row.
     */
    public const int KEY_MAX_LENGTH = 512;

    /**
     * How each rolled-up telemetry type is aggregated: the raw table it is read from, the SQL expression
     * of its key, the SQL condition that counts a row as an error and the SQL expression of its duration
     * (null when the type has no duration statistics). Logs and exceptions are not rolled up.
     *
     * @var array<string, array{raw_table: string, key: string, error: string, duration: string|null}>
     */
    public const array TYPES = [
        'request' => [
            'raw_table' => 'requests',
            'key' => "method || ' ' || coalesce(route, '[unmatched]')",
            'error' => 'status_code >= 500',
            'duration' => 'duration_ms',
        ],
        'query' => [
            'raw_table' => 'queries',
            'key' => 'sql_hash',
            'error' => 'false',
            'duration' => 'duration_ms',
        ],
        'job' => [
            'raw_table' => 'job_runs',
            'key' => 'job_class',
            'error' => "status = 'failed'",
            'duration' => 'duration_ms',
        ],
        'outgoing_request' => [
            'raw_table' => 'outgoing_requests',
            'key' => 'target_host',
            'error' => 'failed',
            'duration' => 'duration_ms',
        ],
        'cache' => [
            'raw_table' => 'cache_events',
            'key' => "split_part(\"key\", ':', 1)",
            'error' => "operation = 'miss'",
            'duration' => null,
        ],
        'command' => [
            'raw_table' => 'command_runs',
            'key' => 'command',
            'error' => 'failed',
            'duration' => 'duration_ms',
        ],
    ];

    /**
     * Create the rollup builder.
     */
    public function __construct(
        protected readonly ConnectionResolverInterface $database,
        protected readonly Repository $config,
    ) {}

    /**
     * Aggregate the raw telemetry recorded in [from, to) into `{unit}` buckets of the given rollup table and
     * return how many rollup rows were inserted or updated.
     *
     * Runs exactly one `INSERT ... SELECT ... ON CONFLICT DO UPDATE` per rolled-up type, grouped by
     * environment, key and bucket, so every environment present in the raw tables gets its own rows and
     * re-running the same range rewrites the same rows with the same values (idempotent). Buckets without
     * events produce no rows. Table and unit are interpolated into the SQL, so both are checked against fixed
     * allow-lists. Runs on the given connection, else `app-watch.connection`.
     *
     * @throws InvalidArgumentException when the table or unit is not allowed, or `to` is not after `from`
     */
    public function handle(string $rollup_table, CarbonImmutable $from, CarbonImmutable $to, string $bucket_unit, ?string $connection_name = null): int
    {
        if (! in_array($rollup_table, self::ROLLUP_TABLES, true)) {
            throw new InvalidArgumentException("Invalid rollup table [{$rollup_table}].");
        }

        if (! in_array($bucket_unit, self::BUCKET_UNITS, true)) {
            throw new InvalidArgumentException("Invalid bucket unit [{$bucket_unit}].");
        }

        if ($to->lessThanOrEqualTo($from)) {
            throw new InvalidArgumentException('to must be after from.');
        }

        $configured_connection = $this->config->get('app-watch.connection');
        $connection = $this->database->connection($connection_name ?? (is_string($configured_connection) ? $configured_connection : null));
        $bindings = [
            $from->utc()->format('Y-m-d H:i:s.uP'),
            $to->utc()->format('Y-m-d H:i:s.uP'),
        ];
        $key_max_length = self::KEY_MAX_LENGTH;
        $upserted = 0;

        foreach (self::TYPES as $type => $definition) {
            $duration = $definition['duration'];

            $duration_columns = $duration === null
                ? 'NULL::double precision, NULL::double precision, NULL::double precision, NULL::double precision, NULL::double precision'
                : "sum({$duration}),
                    percentile_cont(0.5) WITHIN GROUP (ORDER BY {$duration}),
                    percentile_cont(0.95) WITHIN GROUP (ORDER BY {$duration}),
                    percentile_cont(0.99) WITHIN GROUP (ORDER BY {$duration}),
                    max({$duration})";

            $upserted += $connection->affectingStatement(<<<SQL
                INSERT INTO {$rollup_table} (environment_id, type, key, bucket, count, error_count, duration_sum, p50, p95, p99, max)
                SELECT
                    environment_id,
                    '{$type}',
                    left({$definition['key']}, {$key_max_length}),
                    date_trunc('{$bucket_unit}', recorded_at, 'UTC'),
                    count(*),
                    count(*) FILTER (WHERE {$definition['error']}),
                    {$duration_columns}
                FROM {$definition['raw_table']}
                WHERE recorded_at >= ?::timestamptz AND recorded_at < ?::timestamptz
                GROUP BY 1, 2, 3, 4
                ON CONFLICT (environment_id, type, key, bucket) DO UPDATE SET
                    count = EXCLUDED.count,
                    error_count = EXCLUDED.error_count,
                    duration_sum = EXCLUDED.duration_sum,
                    p50 = EXCLUDED.p50,
                    p95 = EXCLUDED.p95,
                    p99 = EXCLUDED.p99,
                    max = EXCLUDED.max
                SQL, $bindings);
        }

        return $upserted;
    }
}
