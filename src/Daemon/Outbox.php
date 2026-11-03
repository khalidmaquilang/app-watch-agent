<?php

declare(strict_types=1);

namespace AppWatch\Agent\Daemon;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;

/**
 * Holds the flushed events until the monitor takes them. A failed delivery is retried with
 * exponential backoff (1 s, 2 s, 4 s ... at most MAX_BACKOFF_SECONDS, or the monitor's
 * Retry-After). deliver() never sleeps: while backing off it returns at once, so the daemon keeps
 * reading from its socket. Memory is bounded: past `app-watch.daemon.max_buffered_events` the
 * oldest events are dropped and counted (until the disk spool takes them over).
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
     * Events dropped because the outbox was full.
     */
    private int $dropped_count = 0;

    /**
     * The outbox's collaborators.
     */
    public function __construct(
        private readonly IngestClient $client,
        private readonly Repository $config,
    ) {}

    /**
     * Queue events for delivery, dropping the oldest ones past the memory cap.
     *
     * @param  array<int, array<string, mixed>>  $events
     */
    public function push(array $events): void
    {
        if ($events === []) {
            return;
        }

        array_push($this->pending, ...array_values($events));

        $overflow = count($this->pending) - $this->maxBufferedEvents();

        if ($overflow > 0) {
            $this->pending = array_slice($this->pending, $overflow);
            $this->dropped_count += $overflow;
        }
    }

    /**
     * Try to deliver every pending event unless the outbox is backing off. Returns the attempt's
     * result, or null when nothing was attempted.
     */
    public function deliver(): ?IngestResult
    {
        if ($this->pending === [] || $this->isBackingOff()) {
            return null;
        }

        $result = $this->client->send($this->pending);

        $this->pending = $result->pending;

        if ($result->shouldRetry()) {
            $this->failed_attempts++;
            $this->next_attempt_at = CarbonImmutable::now()->addSeconds($this->backoffSeconds($result));
        } else {
            $this->failed_attempts = 0;
            $this->next_attempt_at = null;
        }

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
     * Events dropped because the outbox was full.
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
     * The wait before the next attempt: the monitor's Retry-After when it sent one, otherwise
     * 2^(failures - 1) seconds, at most MAX_BACKOFF_SECONDS.
     */
    private function backoffSeconds(IngestResult $result): int
    {
        if ($result->retry_after_seconds !== null) {
            return max(1, $result->retry_after_seconds);
        }

        return min(self::MAX_BACKOFF_SECONDS, 2 ** min($this->failed_attempts - 1, 6));
    }
}
