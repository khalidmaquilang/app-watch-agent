<?php

declare(strict_types=1);

namespace AppWatch\Agent\Console;

use AppWatch\Agent\Daemon\Daemon;
use AppWatch\Agent\Daemon\Outbox;
use AppWatch\Agent\Daemon\WriteResult;
use AppWatch\Agent\Support\AgentConfiguration;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use RuntimeException;

/**
 * Runs the agent daemon: receives the events app processes send over TCP and writes them in
 * batches to the telemetry database. On SIGTERM or SIGINT it stops accepting, makes one final
 * delivery attempt, spools what could not be sent and exits 0. Without the pcntl extension it
 * runs without signal handling.
 */
final class AgentCommand extends Command
{
    /**
     * The console command signature. `--max-ticks` bounds the loop (tests); 0 runs until stopped.
     *
     * @var string
     */
    protected $signature = 'app-watch:agent
        {--max-ticks=0 : Stop after this many loop ticks (0 runs until stopped)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run the App Watch agent daemon that writes recorded events to the telemetry database';

    /**
     * SQLSTATEs already reported as rejections since the last successful write, so a bad payload
     * or a missing partition is logged once instead of on every batch.
     *
     * @var array<string, true>
     */
    private array $reported_sqlstates = [];

    /**
     * Outbox drops already reported.
     */
    private int $reported_dropped_count = 0;

    /**
     * Spooled events already reported.
     */
    private int $reported_spooled_count = 0;

    /**
     * Spooled events evicted to respect the spool size cap, already reported.
     */
    private int $reported_evicted_count = 0;

    /**
     * Corrupt spool files already reported.
     */
    private int $reported_corrupt_count = 0;

    /**
     * The signal that asked the daemon to stop; null while it should keep running.
     */
    private ?int $stop_signal = null;

    /**
     * Check the configuration, listen, and run the loop.
     */
    public function handle(Repository $config, AgentConfiguration $agent_configuration, Daemon $daemon): int
    {
        if (! $agent_configuration->isConfigured()) {
            $this->writeError($this->notConfiguredMessage($config, $agent_configuration));

            return self::FAILURE;
        }

        $host = $config->get('app-watch.daemon.host', '127.0.0.1');
        $port = $config->get('app-watch.daemon.port', 2407);

        try {
            $daemon->listen(is_string($host) ? $host : '127.0.0.1', is_numeric($port) ? (int) $port : 2407);
        } catch (RuntimeException $exception) {
            $this->writeError($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("App Watch agent listening on tcp://{$daemon->address()}");

        $option = $this->option('max-ticks');
        $max_ticks = is_numeric($option) ? max(0, (int) $option) : 0;
        $ticks = 0;
        $signals_registered = $this->registerSignalHandlers();

        try {
            while ($this->stop_signal === null && ($max_ticks === 0 || $ticks < $max_ticks)) {
                $ticks++;

                $this->report($daemon->tick(), $daemon->outbox());
            }

            if ($this->stop_signal !== null) {
                $this->writeError("Received signal {$this->stop_signal}; flushing buffered events and stopping.");
                $this->report($daemon->shutdown(), $daemon->outbox());
            }
        } finally {
            $daemon->close();

            if ($signals_registered) {
                $this->restoreSignalHandlers();
            }
        }

        return self::SUCCESS;
    }

    /**
     * The SIGTERM/SIGINT handler: ask the loop to stop after the current tick.
     */
    public function stop(int $signal): void
    {
        $this->stop_signal = $signal;
    }

    /**
     * Why the agent is not configured: disabled, no environment id, or no telemetry connection.
     */
    private function notConfiguredMessage(Repository $config, AgentConfiguration $agent_configuration): string
    {
        if (! (bool) $config->get('app-watch.enabled', true)) {
            return 'App Watch is disabled (APP_WATCH_ENABLED=false); the agent daemon does not start.';
        }

        if ($agent_configuration->environmentId() === null) {
            return 'APP_WATCH_ENVIRONMENT_ID is not set. The agent daemon needs the environment id shown on the App Watch Settings -> Environments page.';
        }

        $connection = $config->get('app-watch.connection');
        $name = is_string($connection) && $connection !== '' ? $connection : 'app_watch';

        return "The telemetry database connection \"{$name}\" is not configured. Set APP_WATCH_DB_HOST, APP_WATCH_DB_PORT, APP_WATCH_DB_DATABASE, APP_WATCH_DB_USERNAME and APP_WATCH_DB_PASSWORD (or point APP_WATCH_DB_CONNECTION at an existing connection).";
    }

    /**
     * Handle SIGTERM and SIGINT asynchronously; false (with a warning) when pcntl is unavailable.
     */
    private function registerSignalHandlers(): bool
    {
        if (! function_exists('pcntl_async_signals') || ! function_exists('pcntl_signal')) {
            $this->writeError('The pcntl extension is not available: the agent daemon runs without SIGTERM/SIGINT handling, so events buffered when it is stopped are lost.');

            return false;
        }

        pcntl_async_signals(true);
        $handler = function (int $signal): void {
            $this->stop($signal);
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);

        return true;
    }

    /**
     * Give SIGTERM and SIGINT back their default behaviour.
     */
    private function restoreSignalHandlers(): void
    {
        pcntl_signal(SIGTERM, SIG_DFL);
        pcntl_signal(SIGINT, SIG_DFL);
    }

    /**
     * Write a delivery attempt's problems to STDERR: each rejection SQLSTATE once until a batch is
     * written again, every poisoned spool file, every retry, and newly spooled, evicted, corrupt
     * and dropped events.
     */
    private function report(?WriteResult $result, Outbox $outbox): void
    {
        if ($result !== null) {
            $this->reportResult($result, $outbox);
        }

        $spool = $outbox->spool();
        $spooled_count = $outbox->spooledCount();

        if ($spooled_count > $this->reported_spooled_count) {
            $this->writeError('Spooled '.($spooled_count - $this->reported_spooled_count)." events to {$spool->path()} until the database can take them.");
            $this->reported_spooled_count = $spooled_count;
        }

        $evicted_count = $spool->evictedCount();

        if ($evicted_count > $this->reported_evicted_count) {
            $this->writeError('Dropped '.($evicted_count - $this->reported_evicted_count).' spooled events: the spool reached its size limit ('.$spool->maxBytes().' bytes), so the oldest batches were deleted.');
            $this->reported_evicted_count = $evicted_count;
        }

        $corrupt_count = $spool->corruptCount();

        if ($corrupt_count > $this->reported_corrupt_count) {
            $this->writeError('Deleted '.($corrupt_count - $this->reported_corrupt_count).' corrupt spool files.');
            $this->reported_corrupt_count = $corrupt_count;
        }

        $dropped_count = $outbox->droppedCount();

        if ($dropped_count > $this->reported_dropped_count) {
            $this->writeError('Dropped '.($dropped_count - $this->reported_dropped_count).' events: they could not be spooled ('.($spool->lastError() ?? 'unknown error').').');
            $this->reported_dropped_count = $dropped_count;
        }
    }

    /**
     * Write one delivery attempt's rejections and retries to STDERR. A rejection names the
     * SQLSTATE only, never the query, its data or the connection credentials.
     */
    private function reportResult(WriteResult $result, Outbox $outbox): void
    {
        if ($result->stored_count > 0) {
            $this->reported_sqlstates = [];
        }

        if ($result->isPoison()) {
            $sqlstate = $result->poison_sqlstate ?? 'unknown';
            $deleted_spool_file = $result->spool_file !== null && ! $result->shouldRetry();
            $first_report = ! isset($this->reported_sqlstates[$sqlstate]);

            if ($deleted_spool_file) {
                $this->writeError('Deleted spooled batch '.basename((string) $result->spool_file).": {$result->poison_count} of its events were rejected by the database (SQLSTATE {$sqlstate}).");
            } elseif ($first_report) {
                $this->writeError("{$result->poison_count} events were rejected by the database (SQLSTATE {$sqlstate}); they were dropped.");
            }

            if ($result->isMissingPartition() && ($deleted_spool_file || $first_report)) {
                $this->writeError('No partition exists for the events\' recorded_at day: the scheduler is not running `php artisan app-watch:partitions`. Make sure `php artisan schedule:run` runs every minute.');
            }

            $this->reported_sqlstates[$sqlstate] = true;
        }

        if ($result->shouldRetry()) {
            $retry_at = $outbox->nextAttemptAt()?->toIso8601String() ?? 'now';
            $source = $result->spool_file === null ? '' : 'spooled ';
            $this->writeError('Could not write '.count($result->pending)." {$source}events to the database ({$result->failure}); retrying at {$retry_at}.");
        }
    }

    /**
     * Write a line to STDERR.
     */
    private function writeError(string $message): void
    {
        $this->output->getErrorStyle()->writeln("<error>{$message}</error>");
    }
}
