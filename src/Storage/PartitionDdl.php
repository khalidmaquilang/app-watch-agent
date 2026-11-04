<?php

declare(strict_types=1);

namespace AppWatch\Agent\Storage;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * Creates the daily partitions `{table}_pYYYYMMDD` of the raw telemetry tables (Schema::PARTITIONED_TABLES).
 */
final class PartitionDdl
{
    /**
     * Create the partition of every raw telemetry table for each UTC day in [from, from + days_ahead]
     * on the given connection and return how many partitions were created.
     *
     * Boundaries are always UTC midnights, whatever the timezone of `$from` is. Idempotent: partitions
     * that already exist are skipped and not counted.
     *
     * @throws InvalidArgumentException when days_ahead is negative
     */
    public function createDaily(ConnectionInterface $connection, CarbonImmutable $from, int $days_ahead): int
    {
        if ($days_ahead < 0) {
            throw new InvalidArgumentException('days_ahead must be zero or more.');
        }

        $first_day = $from->utc()->startOfDay();
        $created = 0;

        for ($offset = 0; $offset <= $days_ahead; $offset++) {
            $day = $first_day->addDays($offset);
            $next_day = $day->addDay();

            foreach (Schema::PARTITIONED_TABLES as $table) {
                $partition = sprintf('%s_p%s', $table, $day->format('Ymd'));

                if ($connection->scalar('SELECT to_regclass(?) IS NOT NULL', [$partition]) === true) {
                    continue;
                }

                $connection->statement(sprintf(
                    "CREATE TABLE IF NOT EXISTS %s PARTITION OF %s FOR VALUES FROM ('%s 00:00:00+00') TO ('%s 00:00:00+00')",
                    $partition,
                    $table,
                    $day->format('Y-m-d'),
                    $next_day->format('Y-m-d'),
                ));

                $created++;
            }
        }

        return $created;
    }

    /**
     * Count the existing daily partitions of the raw telemetry tables on the given connection.
     */
    public function count(ConnectionInterface $connection): int
    {
        $placeholders = implode(', ', array_fill(0, count(Schema::PARTITIONED_TABLES), '?'));
        $count = $connection->scalar(
            "SELECT count(*) FROM pg_inherits i JOIN pg_class parent ON parent.oid = i.inhparent
             WHERE parent.relname IN ({$placeholders}) AND parent.relnamespace = current_schema()::regnamespace",
            Schema::PARTITIONED_TABLES,
        );

        return is_numeric($count) ? (int) $count : 0;
    }
}
