<?php

declare(strict_types=1);

namespace AppWatch\Agent\Maintenance;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;

/**
 * Deletes the expired minute and hour rollups from the client's database.
 */
final class PruneRollups
{
    /**
     * The maximum number of rows deleted by one DELETE statement, so pruning never holds long locks.
     */
    public const int CHUNK_SIZE = 10000;

    /**
     * The minute rollup retention used when `app-watch.rollups.minute_retention_days` is not numeric.
     */
    public const int DEFAULT_MINUTE_RETENTION_DAYS = 7;

    /**
     * The hour rollup retention used when `app-watch.rollups.hour_retention_days` is not numeric.
     */
    public const int DEFAULT_HOUR_RETENTION_DAYS = 90;

    /**
     * Create the rollup pruner.
     */
    public function __construct(
        protected readonly ConnectionResolverInterface $database,
        protected readonly Repository $config,
    ) {}

    /**
     * Delete the minute rollups whose bucket is strictly older than `now − app-watch.rollups.minute_retention_days`
     * and the hour rollups whose bucket is strictly older than `now − app-watch.rollups.hour_retention_days`, and
     * return how many rows were deleted from each table.
     *
     * A bucket exactly at the cutoff is kept (only strictly older buckets are deleted). Rollup tables are not
     * partitioned, so rows are deleted in chunks of CHUNK_SIZE (`DELETE ... WHERE id IN (SELECT id ... LIMIT n)`),
     * each its own statement, until a chunk deletes fewer rows. Rollups of every environment are pruned. Both
     * retention values are validated before anything is deleted. Idempotent: a second run with the same `now`
     * deletes nothing. Runs on the given connection, else `app-watch.connection`.
     *
     * @throws InvalidArgumentException when a retention value is below 1
     */
    public function handle(?CarbonImmutable $now = null, ?string $connection_name = null): PruneResult
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $minute_retention_days = $this->retentionDays('minute_retention_days', self::DEFAULT_MINUTE_RETENTION_DAYS);
        $hour_retention_days = $this->retentionDays('hour_retention_days', self::DEFAULT_HOUR_RETENTION_DAYS);

        $configured_connection = $this->config->get('app-watch.connection');
        $connection = $this->database->connection($connection_name ?? (is_string($configured_connection) ? $configured_connection : null));
        $deleted = [];

        foreach ([['rollups_minute', $minute_retention_days], ['rollups_hour', $hour_retention_days]] as [$table, $retention_days]) {
            $cutoff = $now->subDays($retention_days)->format('Y-m-d H:i:s.uP');
            $table_deleted = 0;

            do {
                $chunk_deleted = $connection->table($table)
                    ->whereIn('id', $connection->table($table)
                        ->select('id')
                        ->where('bucket', '<', $cutoff)
                        ->orderBy('id')
                        ->limit(self::CHUNK_SIZE))
                    ->delete();

                $table_deleted += $chunk_deleted;
            } while ($chunk_deleted === self::CHUNK_SIZE);

            $deleted[] = $table_deleted;
        }

        return new PruneResult(
            minute_deleted: $deleted[0],
            hour_deleted: $deleted[1],
        );
    }

    /**
     * The configured `app-watch.rollups.{key}` as a number of days.
     *
     * @throws InvalidArgumentException when it is below 1
     */
    private function retentionDays(string $key, int $default): int
    {
        $configured = $this->config->get("app-watch.rollups.{$key}", $default);
        $retention_days = is_numeric($configured) ? (int) $configured : $default;

        if ($retention_days < 1) {
            throw new InvalidArgumentException("app-watch.rollups.{$key} must be at least 1.");
        }

        return $retention_days;
    }
}
