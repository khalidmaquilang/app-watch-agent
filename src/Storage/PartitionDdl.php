<?php

declare(strict_types=1);

namespace AppWatch\Agent\Storage;

use Illuminate\Database\ConnectionInterface;

/**
 * Inspects the daily partitions `{table}_pYYYYMMDD` of the raw telemetry tables (Schema::PARTITIONED_TABLES).
 * They are created by AppWatch\Agent\Maintenance\CreatePartitions.
 */
final class PartitionDdl
{
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
