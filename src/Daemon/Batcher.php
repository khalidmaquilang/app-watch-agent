<?php

declare(strict_types=1);

namespace AppWatch\Agent\Daemon;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;

/**
 * Buffers the events the daemon receives until a batch is due: when FLUSH_EVENTS events are
 * buffered, or when the oldest buffered event has waited FLUSH_SECONDS. Both are configurable
 * (`app-watch.daemon.flush_events` / `flush_seconds`).
 */
final class Batcher
{
    /**
     * Flush once this many events are buffered.
     */
    public const int FLUSH_EVENTS = 500;

    /**
     * Flush once the oldest buffered event has waited this many seconds.
     */
    public const int FLUSH_SECONDS = 5;

    /**
     * The buffered events, oldest first.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $events = [];

    /**
     * When the oldest buffered event arrived; null while the buffer is empty.
     */
    private ?CarbonImmutable $first_buffered_at = null;

    /**
     * Read the flush thresholds from the app-watch config.
     */
    public function __construct(
        private readonly Repository $config,
    ) {}

    /**
     * Buffer one event.
     *
     * @param  array<string, mixed>  $event
     */
    public function add(array $event): void
    {
        $this->first_buffered_at ??= CarbonImmutable::now();
        $this->events[] = $event;
    }

    /**
     * How many events are buffered.
     */
    public function count(): int
    {
        return count($this->events);
    }

    /**
     * Whether a batch is due: the event threshold is reached, or the oldest event has waited the
     * flush interval.
     */
    public function shouldFlush(): bool
    {
        if ($this->first_buffered_at === null) {
            return false;
        }

        if (count($this->events) >= $this->flushEvents()) {
            return true;
        }

        return $this->first_buffered_at->addSeconds($this->flushSeconds())->lessThanOrEqualTo(CarbonImmutable::now());
    }

    /**
     * Return every buffered event and empty the buffer.
     *
     * @return array<int, array<string, mixed>>
     */
    public function flush(): array
    {
        $events = $this->events;

        $this->events = [];
        $this->first_buffered_at = null;

        return $events;
    }

    /**
     * The configured event threshold (at least 1).
     */
    public function flushEvents(): int
    {
        $flush_events = $this->config->get('app-watch.daemon.flush_events', self::FLUSH_EVENTS);

        return is_numeric($flush_events) ? max(1, (int) $flush_events) : self::FLUSH_EVENTS;
    }

    /**
     * The configured flush interval in seconds (at least 1).
     */
    public function flushSeconds(): int
    {
        $flush_seconds = $this->config->get('app-watch.daemon.flush_seconds', self::FLUSH_SECONDS);

        return is_numeric($flush_seconds) ? max(1, (int) $flush_seconds) : self::FLUSH_SECONDS;
    }
}
