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
 * batches to the App Watch monitor.
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

        try {
            while ($max_ticks === 0 || $ticks < $max_ticks) {
                $ticks++;

                $this->report($daemon->tick(), $daemon->outbox());
            }
        } finally {
            $daemon->close();
        }

        return self::SUCCESS;
    }

    /**
     * Write a delivery attempt's problems to STDERR: each rejection status once until a batch is
     * delivered again, every retry, and newly dropped events.
     */
    private function report(?IngestResult $result, Outbox $outbox): void
    {
        if ($result !== null) {
            if ($result->delivered_count > 0) {
                $this->reported_statuses = [];
            }

            if ($result->rejected_status !== null && ! isset($this->reported_statuses[$result->rejected_status])) {
                $this->reported_statuses[$result->rejected_status] = true;
                $this->writeError("The monitor rejected {$result->rejected_count} events with HTTP {$result->rejected_status}; they were dropped. Check APP_WATCH_TOKEN and the agent version.");
            }

            if ($result->shouldRetry()) {
                $retry_at = $outbox->nextAttemptAt()?->toIso8601String() ?? 'now';
                $this->writeError('Could not deliver '.count($result->pending)." events ({$result->failure}); retrying at {$retry_at}.");
            }
        }

        $dropped_count = $outbox->droppedCount();

        if ($dropped_count > $this->reported_dropped_count) {
            $this->writeError('Dropped '.($dropped_count - $this->reported_dropped_count).' events: the in-memory buffer is full while the monitor is unreachable.');
            $this->reported_dropped_count = $dropped_count;
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
