<?php

declare(strict_types=1);

namespace AppWatch\Agent\Console;

use AppWatch\Agent\Maintenance\CreatePartitions;
use AppWatch\Agent\Maintenance\DropExpiredPartitions;
use AppWatch\Agent\Storage\Schema;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * Daily partition upkeep of the raw telemetry tables in the client's database: drops the partitions
 * older than `app-watch.retention_days`, then creates the ones for today … today +
 * `app-watch.partitions_ahead_days` (UTC). Re-runnable: a second run the same day changes nothing.
 */
final class PartitionsCommand extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'app-watch:partitions
        {--database= : The database connection holding the telemetry schema (default: the app-watch.connection config, else the default connection)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create the upcoming daily raw telemetry partitions and drop the expired ones';

    /**
     * The console command help text.
     *
     * @var string
     */
    protected $help = 'Each partition is created or dropped by its own statement, without a wrapping transaction. '
        .'The connection must be a direct or session-mode Postgres connection: transaction-mode poolers '
        .'(e.g. PgBouncer in transaction mode, Supabase port 6543) break the partition DDL.';

    /**
     * Drop the expired partitions, create the upcoming ones and print both counts.
     *
     * Dropping runs first so an invalid retention_days fails before anything is created or dropped.
     */
    public function handle(Repository $config, CreatePartitions $create_partitions, DropExpiredPartitions $drop_expired_partitions): int
    {
        $connection_name = $this->connectionName($config);

        if ($connection_name === null || ! is_array($config->get("database.connections.{$connection_name}"))) {
            $this->components->error('Database connection ['.($connection_name ?? '').'] is not configured. Pass an existing connection name with --database=.');

            return self::FAILURE;
        }

        $now = CarbonImmutable::now('UTC');
        $retention_days = $config->get('app-watch.retention_days', Schema::DEFAULT_RETENTION_DAYS);
        $ahead_days = $config->get('app-watch.partitions_ahead_days', Schema::DEFAULT_PARTITIONS_AHEAD_DAYS);

        try {
            $dropped = $drop_expired_partitions->handle(
                $now,
                is_numeric($retention_days) ? (int) $retention_days : Schema::DEFAULT_RETENTION_DAYS,
                $connection_name,
            );
            $created = $create_partitions->handle(
                $now,
                is_numeric($ahead_days) ? (int) $ahead_days : Schema::DEFAULT_PARTITIONS_AHEAD_DAYS,
                $connection_name,
            );
        } catch (InvalidArgumentException $invalid_argument_exception) {
            $this->components->error($invalid_argument_exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf('Created %d partition(s), dropped %d partition(s).', $created, $dropped));

        return self::SUCCESS;
    }

    /**
     * The connection from --database, else `app-watch.connection`, else the default connection.
     */
    private function connectionName(Repository $config): ?string
    {
        foreach ([$this->option('database'), $config->get('app-watch.connection'), $config->get('database.default')] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }
}
