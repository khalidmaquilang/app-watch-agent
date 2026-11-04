<?php

declare(strict_types=1);

namespace AppWatch\Agent\Daemon;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;

/**
 * Holds the flushed events until the telemetry database takes them. A retryable failure (database
 * down, timeout, deadlock ...) is retried with exponential backoff (1 s, 2 s, 4 s ... at most
 * MAX_BACKOFF_SECONDS). deliver() never sleeps: while backing off it returns at once, so the
 * daemon keeps reading from its socket.
 *
 * Memory is bounded by the disk spool: once SPOOL_AFTER_ATTEMPTS deliveries in a row have failed,
 * or more than `app-watch.daemon.max_buffered_events` are pending, the pending events are written
 * to the spool instead of being held in memory. Events the spool cannot take (disk full, directory
 * not writable) are dropped and counted. Spooled batches (including those left by an earlier run)
 * are replayed oldest first, one per deliver() call and only while nothing live is pending, so
 * live traffic is never starved; a failed replay keeps the batch and backs off like any delivery,
 * so the spooled batch doubles as the retry while the app is idle. A batch is deleted once every
 * chunk of it was either written or rejected by the database as poison (SQLSTATE class 22/23).
 */
final class Outbox
{
    /**
     * The longest exponential backoff, in seconds.
     */
    public const int MAX_BACKOFF_SECONDS = 60;

    /**
     * The most events held in memory by default.
     */
    public const int MAX_BUFFERED_EVENTS = 10_000;

    /**
     * Failed deliveries in a row after which the pending events are spooled.
     */
    public const int SPOOL_AFTER_ATTEMPTS = 3;

    /**
     * How long the final delivery attempt on shutdown may take, in seconds.
     */
    public const int SHUTDOWN_TIMEOUT_SECONDS = 3;

    /**
     * The events waiting to be delivered, oldest first.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $pending = [];

    /**
     * Failed deliveries in a row.
     */
    private int $failed_attempts = 0;

    /**
     * When delivery may be tried again; null when it may be tried now.
     */
    private ?CarbonImmutable $next_attempt_at = null;

    /**
     * Whether the spool may hold batches: true on startup and after every spooled write, false
     * once a replay found it empty, so an empty spool directory is not scanned on every tick.
     */
    private bool $spool_may_have_batches = true;

    /**
     * Events written to the spool.
     */
    private int $spooled_count = 0;

    /**
     * Events dropped because the spool could not take them.
     */
    private int $dropped_count = 0;

    /**
     * The outbox's collaborators.
     */
    public function __construct(
        private readonly DatabaseWriter $writer,
        private readonly Spool $spool,
        private readonly Repository $config,
    ) {}

    /**
     * Queue events for delivery, spooling every pending event once the memory cap is exceeded.
     *
     * @param  array<int, array<string, mixed>>  $events
     */
    public function push(array $events): void
    {
        if ($events === []) {
            return;
        }

        array_push($this->pending, ...array_values($events));

        if (count($this->pending) > $this->maxBufferedEvents()) {
            $this->spoolPending();
        }
    }

    /**
     * Unless the outbox is backing off, try to deliver every pending event, or, when nothing is
     * pending, replay the oldest spooled batch. Returns the attempt's result, or null when
     * nothing was attempted.
     */
    public function deliver(): ?WriteResult
    {
        if ($this->isBackingOff()) {
            return null;
        }

        if ($this->pending !== []) {
            return $this->deliverPending();
        }

        return $this->spool_may_have_batches ? $this->replayOldest() : null;
    }

    /**
     * The final delivery on shutdown: one attempt with a SHUTDOWN_TIMEOUT_SECONDS statement
     * timeout, whatever the backoff, and
     * every event it could not deliver goes to the spool. Returns the attempt's result, or null
     * when nothing was pending.
     */
    public function flushForShutdown(): ?WriteResult
    {
        if ($this->pending === []) {
            return null;
        }

        $result = $this->writer->send($this->pending, self::SHUTDOWN_TIMEOUT_SECONDS);

        $this->pending = $result->pending;
        $this->spoolPending();

        return $result;
    }

    /**
     * Whether a failed delivery is waiting for its retry time.
     */
    public function isBackingOff(): bool
    {
        return $this->next_attempt_at !== null && CarbonImmutable::now()->lessThan($this->next_attempt_at);
    }

    /**
     * When delivery will be tried again, null if it may be tried now.
     */
    public function nextAttemptAt(): ?CarbonImmutable
    {
        return $this->next_attempt_at;
    }

    /**
     * The events waiting to be delivered, oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pending(): array
    {
        return $this->pending;
    }

    /**
     * The disk spool the outbox overflows into.
     */
    public function spool(): Spool
    {
        return $this->spool;
    }

    /**
     * Events written to the spool.
     */
    public function spooledCount(): int
    {
        return $this->spooled_count;
    }

    /**
     * Events dropped because the spool could not take them.
     */
    public function droppedCount(): int
    {
        return $this->dropped_count;
    }

    /**
     * The configured memory cap (at least 1).
     */
    public function maxBufferedEvents(): int
    {
        $max = $this->config->get('app-watch.daemon.max_buffered_events', self::MAX_BUFFERED_EVENTS);

        return is_numeric($max) ? max(1, (int) $max) : self::MAX_BUFFERED_EVENTS;
    }

    /**
     * Write the pending events; spool them once SPOOL_AFTER_ATTEMPTS deliveries in a row failed.
     */
    private function deliverPending(): WriteResult
    {
        $result = $this->writer->send($this->pending);

        $this->pending = $result->pending;
        $this->recordAttempt($result);

        if ($result->shouldRetry() && $this->failed_attempts >= self::SPOOL_AFTER_ATTEMPTS) {
            $this->spoolPending();
        }

        return $result;
    }

    /**
     * Write the oldest spooled batch. It is deleted once nothing of it is left to retry (every
     * chunk written or dropped as poison); when a retryable failure stopped it part-way, only the
     * unwritten rest is written back in its place, and replay backs off like any delivery.
     */
    private function replayOldest(): ?WriteResult
    {
        $batch = $this->spool->oldest();

        if ($batch === null) {
            $this->spool_may_have_batches = false;

            return null;
        }

        $result = $this->writer->send($batch->events);

        $this->recordAttempt($result);

        if (! $result->shouldRetry()) {
            $this->spool->delete($batch->path);
        } elseif (count($result->pending) < count($batch->events)) {
            $this->spool->replace($batch->path, $result->pending);
        }

        return $result->forSpoolFile($batch->path);
    }

    /**
     * Update the backoff after a delivery attempt.
     */
    private function recordAttempt(WriteResult $result): void
    {
        if ($result->shouldRetry()) {
            $this->failed_attempts++;
            $this->next_attempt_at = CarbonImmutable::now()->addSeconds($this->backoffSeconds());

            return;
        }

        $this->failed_attempts = 0;
        $this->next_attempt_at = null;
    }

    /**
     * Move every pending event to the spool, dropping (and counting) them if it cannot take them.
     */
    private function spoolPending(): void
    {
        if ($this->pending === []) {
            return;
        }

        $count = count($this->pending);

        if ($this->spool->write($this->pending)) {
            $this->spooled_count += $count;
            $this->spool_may_have_batches = true;
        } else {
            $this->dropped_count += $count;
        }

        $this->pending = [];
    }

    /**
     * The wait before the next attempt: 2^(failures - 1) seconds, at most MAX_BACKOFF_SECONDS.
     */
    private function backoffSeconds(): int
    {
        return min(self::MAX_BACKOFF_SECONDS, 2 ** min($this->failed_attempts - 1, 6));
    }
}
