<?php

declare(strict_types=1);

namespace AppWatch\Agent\Maintenance;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Creates the daily partitions `{table}_pYYYYMMDD` of the raw telemetry tables.
 *
 * Every partition is created by its own statement, outside any transaction, so it also works through
 * a session-mode pooler. Transaction-mode poolers (PgBouncer in transaction mode, Supabase port 6543)
 * are not supported for this DDL.
 */
final class CreatePartitions
{
    /**
     * Create the partition creator.
     */
    public function __construct(
        protected readonly PartitionedTables $partitioned_tables,
        protected readonly DatabaseManager $database,
        protected readonly Repository $config,
    ) {}

    /**
     * Create the partition of every configured raw telemetry table for each UTC day in
     * [now, now + ahead_days] and return how many partitions were created.
     *
     * Boundaries are always UTC midnights `[day 00:00 UTC, next day 00:00 UTC)`, whatever the timezone
     * of `$now` or of the host app is. Idempotent: partitions that already exist are skipped and not
     * counted. Runs on the given connection, else `app-watch.connection`.
     *
     * @throws InvalidArgumentException when ahead_days is negative or `app-watch.tables` is invalid
     */
    public function handle(CarbonImmutable $now, int $ahead_days, ?string $connection_name = null): int
    {
        if ($ahead_days < 0) {
            throw new InvalidArgumentException('days_ahead must be zero or more.');
        }

        $tables = $this->partitioned_tables->handle();
        $configured_connection = $this->config->get('app-watch.connection');
        $connection = $this->database->connection($connection_name ?? (is_string($configured_connection) ? $configured_connection : null));
        $first_day = $now->utc()->startOfDay();
        $created = 0;

        for ($offset = 0; $offset <= $ahead_days; $offset++) {
            $day = $first_day->addDays($offset);
            $next_day = $day->addDay();

            foreach ($tables as $table) {
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
}
