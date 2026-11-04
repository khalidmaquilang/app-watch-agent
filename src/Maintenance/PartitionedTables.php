<?php

declare(strict_types=1);

namespace AppWatch\Agent\Maintenance;

use AppWatch\Agent\Storage\Schema;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * The raw telemetry tables whose daily partitions the agent maintains, from `app-watch.tables`.
 */
final class PartitionedTables
{
    /**
     * The only table names `app-watch.tables` may contain. The names are interpolated into partition
     * DDL, so anything outside this list is rejected instead of reaching the database.
     *
     * @var list<string>
     */
    public const array WHITELIST = Schema::PARTITIONED_TABLES;

    /**
     * Create the table list reader.
     */
    public function __construct(
        protected readonly Repository $config,
    ) {}

    /**
     * Return the configured partitioned tables, in config order and without duplicates.
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException when `app-watch.tables` is not a list of whitelisted table names
     */
    public function handle(): array
    {
        $configured = $this->config->get('app-watch.tables');

        if (! is_array($configured)) {
            throw new InvalidArgumentException('app-watch.tables must be a list of table names.');
        }

        $tables = [];

        foreach ($configured as $table) {
            if (! is_string($table) || ! in_array($table, self::WHITELIST, true)) {
                throw new InvalidArgumentException('Invalid partitioned table name in app-watch.tables.');
            }

            if (! in_array($table, $tables, true)) {
                $tables[] = $table;
            }
        }

        return $tables;
    }
}
