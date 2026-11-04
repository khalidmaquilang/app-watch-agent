<?php

declare(strict_types=1);

namespace AppWatch\Agent\Console;

use AppWatch\Agent\Storage\PartitionDdl;
use AppWatch\Agent\Storage\Schema;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;

/**
 * Installs or upgrades the telemetry schema in the client's database: runs the agent's migrations on
 * the chosen connection (so its `migrations` table lives there too), then creates the daily partitions
 * for today … today + `partitions_ahead_days`. Re-runnable: a second run migrates nothing and creates
 * no partitions.
 */
final class InstallCommand extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'app-watch:install
        {--database= : The database connection to install the telemetry schema on (default: the app-watch.connection config, else the default connection)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install or upgrade the App Watch telemetry schema and create its daily partitions';

    /**
     * The console command help text.
     *
     * @var string
     */
    protected $help = 'The connection must be a direct or session-mode Postgres connection. Transaction-mode poolers '
        .'(e.g. PgBouncer in transaction mode, Supabase port 6543) break the partition DDL. '
        .'Run it again after upgrading the agent: it applies new migrations only.';

    /**
     * Check the connection, migrate, create the partitions and print the installed schema version.
     */
    public function handle(Repository $config, DatabaseManager $database, PartitionDdl $partition_ddl): int
    {
        $connection_name = $this->connectionName($config);

        if ($connection_name === null || ! is_array($config->get("database.connections.{$connection_name}"))) {
            $this->components->error('Database connection ['.($connection_name ?? '').'] is not configured. Pass an existing connection name with --database=.');

            return self::FAILURE;
        }

        $status = $this->call('migrate', [
            '--database' => $connection_name,
            '--path' => Schema::migrationsPath(),
            '--realpath' => true,
            '--force' => true,
        ]);

        if ($status !== self::SUCCESS) {
            $this->components->error("Migrating the telemetry schema on [{$connection_name}] failed.");

            return self::FAILURE;
        }

        $connection = $database->connection($connection_name);
        $days_ahead = $config->get('app-watch.partitions_ahead_days', Schema::DEFAULT_PARTITIONS_AHEAD_DAYS);
        $created = $partition_ddl->createDaily(
            $connection,
            CarbonImmutable::now('UTC'),
            is_numeric($days_ahead) ? max(0, (int) $days_ahead) : Schema::DEFAULT_PARTITIONS_AHEAD_DAYS,
        );
        $version = $connection->table(Schema::VERSIONS_TABLE)->max('version');

        $this->components->info("Created {$created} telemetry partitions on [{$connection_name}].");
        $this->components->info('App Watch telemetry schema version '.(is_numeric($version) ? (int) $version : 0)." is installed on [{$connection_name}].");

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
