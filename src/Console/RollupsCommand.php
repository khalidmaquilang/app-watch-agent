<?php

declare(strict_types=1);

namespace AppWatch\Agent\Console;

use AppWatch\Agent\Maintenance\BuildHourRollups;
use AppWatch\Agent\Maintenance\BuildMinuteRollups;
use AppWatch\Agent\Maintenance\PruneRollups;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * Rollup upkeep in the client's database, run by the scheduler instead of a queue worker (the client may not
 * run one): `minute` re-aggregates the last `app-watch.rollups.grace_minutes` closed minutes, `hour` the last
 * closed hour (run it at `hourlyAt(2)` so it sees the completed grace window) and `prune` deletes the expired
 * minute and hour rollups. Every kind is re-runnable.
 */
final class RollupsCommand extends Command
{
    /**
     * The rollup kinds the command accepts.
     *
     * @var list<string>
     */
    public const array KINDS = ['minute', 'hour', 'prune'];

    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'app-watch:rollups
        {kind : minute|hour|prune}
        {--database= : The database connection holding the telemetry schema (default: the app-watch.connection config, else the default connection)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Build the minute or hour rollups of the raw telemetry, or prune the expired rollups';

    /**
     * Run the requested kind of rollup upkeep and print how many rows it upserted or pruned.
     */
    public function handle(Repository $config, BuildMinuteRollups $build_minute_rollups, BuildHourRollups $build_hour_rollups, PruneRollups $prune_rollups): int
    {
        $kind = $this->argument('kind');

        if (! is_string($kind) || ! in_array($kind, self::KINDS, true)) {
            $this->components->error('Invalid rollup kind ['.(is_string($kind) ? $kind : '').']. Use one of: '.implode(', ', self::KINDS).'.');

            return self::FAILURE;
        }

        $connection_name = $this->connectionName($config);

        if ($connection_name === null || ! is_array($config->get("database.connections.{$connection_name}"))) {
            $this->components->error('Database connection ['.($connection_name ?? '').'] is not configured. Pass an existing connection name with --database=.');

            return self::FAILURE;
        }

        try {
            if ($kind === 'prune') {
                $result = $prune_rollups->handle(connection_name: $connection_name);

                $this->components->info(sprintf('Pruned %d minute rollup(s) and %d hour rollup(s).', $result->minute_deleted, $result->hour_deleted));

                return self::SUCCESS;
            }

            $upserted = $kind === 'minute'
                ? $build_minute_rollups->handle(connection_name: $connection_name)
                : $build_hour_rollups->handle(connection_name: $connection_name);
        } catch (InvalidArgumentException $invalid_argument_exception) {
            $this->components->error($invalid_argument_exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf('Upserted %d %s rollup bucket(s).', $upserted, $kind));

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
