<?php

declare(strict_types=1);

namespace AppWatch\Agent\Console;

use AppWatch\Agent\Daemon\Daemon;
use AppWatch\Agent\Daemon\IngestResult;
use AppWatch\Agent\Daemon\Outbox;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use RuntimeException;

/**
 * Runs the agent daemon: receives the events app processes send over TCP and forwards them in
 * batches to the App Watch monitor. On SIGTERM or SIGINT it stops accepting, makes one final
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
    protected $description = 'Run the App Watch agent daemon that forwards recorded events to the monitor';

    /**
     * HTTP statuses already reported as rejections since the last successful delivery, so a bad
     * token or payload is logged once instead of on every batch.
     *
     * @var array<int, true>
     */
    private array $reported_statuses = [];

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
    public function handle(Repository $config, Daemon $daemon): int
    {
        $token = $config->get('app-watch.token');
        $monitor_url = $config->get('app-watch.monitor_url');

        if (! is_string($token) || $token === '') {
            $this->writeError('APP_WATCH_TOKEN is not set. The agent daemon needs the environment token issued by the App Watch monitor.');

            return self::FAILURE;
        }

        if (! is_string($monitor_url) || $monitor_url === '') {
            $this->writeError('APP_WATCH_URL is not set. The agent daemon needs the App Watch monitor URL.');

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
     * Write a delivery attempt's problems to STDERR: each rejection status once until a batch is
     * delivered again, every poisoned spool file, every retry, and newly spooled, evicted, corrupt
     * and dropped events.
     */
    private function report(?IngestResult $result, Outbox $outbox): void
    {
        if ($result !== null) {
            $this->reportResult($result, $outbox);
        }

        $spool = $outbox->spool();
        $spooled_count = $outbox->spooledCount();

        if ($spooled_count > $this->reported_spooled_count) {
            $this->writeError('Spooled '.($spooled_count - $this->reported_spooled_count)." events to {$spool->path()} until the monitor can take them.");
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
     * Write one delivery attempt's rejections and retries to STDERR.
     */
    private function reportResult(IngestResult $result, Outbox $outbox): void
    {
        if ($result->delivered_count > 0) {
            $this->reported_statuses = [];
        }

        if ($result->spool_file !== null && $result->rejected_status === Outbox::POISON_STATUS) {
            $this->writeError('Deleted spooled batch '.basename($result->spool_file).": the monitor rejected its {$result->rejected_count} events with HTTP 422.");
        } elseif ($result->rejected_status !== null && ! isset($this->reported_statuses[$result->rejected_status])) {
            $this->reported_statuses[$result->rejected_status] = true;
            $this->writeError($result->spool_file === null
                ? "The monitor rejected {$result->rejected_count} events with HTTP {$result->rejected_status}; they were dropped. Check APP_WATCH_TOKEN and the agent version."
                : 'The monitor rejected spooled batch '.basename($result->spool_file)." with HTTP {$result->rejected_status}; it is kept and replay pauses until a delivery succeeds. Check APP_WATCH_TOKEN and the agent version.");
        }

        if ($result->shouldRetry()) {
            $retry_at = $outbox->nextAttemptAt()?->toIso8601String() ?? 'now';
            $source = $result->spool_file === null ? '' : 'spooled ';
            $this->writeError('Could not deliver '.count($result->pending)." {$source}events ({$result->failure}); retrying at {$retry_at}.");
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
