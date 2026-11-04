<?php

declare(strict_types=1);

namespace AppWatch\Agent\Maintenance;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Drops the expired daily partitions of the raw telemetry tables.
 *
 * Every partition is dropped by its own statement, outside any transaction (see CreatePartitions).
 */
final class DropExpiredPartitions
{
    /**
     * Create the partition dropper.
     */
    public function __construct(
        protected readonly PartitionedTables $partitioned_tables,
        protected readonly DatabaseManager $database,
        protected readonly Repository $config,
    ) {}

    /**
     * Drop the daily partitions of the configured raw telemetry tables whose upper bound is at or
     * before the start of the UTC day `now - retention_days`, and return how many were dropped.
     *
     * Partitions are listed from `pg_inherits` (children of the configured parents only, never a table
     * that merely matches the name pattern) and their day is parsed from the `_pYYYYMMDD` suffix; child
     * tables that do not match `{table}_pYYYYMMDD` exactly are left alone. Idempotent: a second run with
     * the same arguments drops nothing. Runs on the given connection, else `app-watch.connection`.
     *
     * @throws InvalidArgumentException when retention_days is below 1 (today's partition is never dropped)
     *                                  or `app-watch.tables` is invalid
     */
    public function handle(CarbonImmutable $now, int $retention_days, ?string $connection_name = null): int
    {
        if ($retention_days < 1) {
            throw new InvalidArgumentException('retention_days must be at least 1.');
        }

        $tables = $this->partitioned_tables->handle();

        if ($tables === []) {
            return 0;
        }

        $configured_connection = $this->config->get('app-watch.connection');
        $connection = $this->database->connection($connection_name ?? (is_string($configured_connection) ? $configured_connection : null));
        $cutoff = $now->utc()->startOfDay()->subDays($retention_days);
        $dropped = 0;

        /** @var list<object{parent: string, partition: string}> $partitions */
        $partitions = $connection->select(
            sprintf(
                'SELECT parent.relname AS parent, child.relname AS partition
                 FROM pg_inherits
                 JOIN pg_class parent ON parent.oid = pg_inherits.inhparent
                 JOIN pg_class child ON child.oid = pg_inherits.inhrelid
                 WHERE parent.relnamespace = current_schema()::regnamespace
                   AND parent.relname IN (%s)
                 ORDER BY child.relname',
                implode(', ', array_fill(0, count($tables), '?')),
            ),
            $tables,
        );

        foreach ($partitions as $partition) {
            if (preg_match('/^'.preg_quote($partition->parent, '/').'_p(\d{8})$/', $partition->partition, $matches) !== 1) {
                continue;
            }

            $day = CarbonImmutable::createFromFormat('!Ymd', $matches[1], 'UTC');

            if (! $day instanceof CarbonImmutable || $day->format('Ymd') !== $matches[1]) {
                continue;
            }

            if ($day->addDay()->greaterThan($cutoff)) {
                continue;
            }

            $connection->statement(sprintf('DROP TABLE IF EXISTS %s', $partition->partition));

            $dropped++;
        }

        return $dropped;
    }
}
