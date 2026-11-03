<?php

declare(strict_types=1);

namespace AppWatch\Agent\Transport;

use AppWatch\Agent\Payloads\EventPayload;

/**
 * Keeps sent events in memory, for tests.
 */
final class FakeTransport implements Transport
{
    /**
     * Every batch sent so far, in order.
     *
     * @var array<int, array<int, EventPayload>>
     */
    private array $batches = [];

    /**
     * Keep the events as one batch.
     *
     * @param  array<int, EventPayload>  $events
     */
    public function send(array $events): void
    {
        $this->batches[] = array_values($events);
    }

    /**
     * Every batch sent so far, in order.
     *
     * @return array<int, array<int, EventPayload>>
     */
    public function batches(): array
    {
        return $this->batches;
    }

    /**
     * Every event sent so far, optionally only those of the given trace.
     *
     * @return array<int, EventPayload>
     */
    public function events(?string $trace_id = null): array
    {
        $events = array_merge(...$this->batches);

        if ($trace_id === null) {
            return $events;
        }

        return array_values(array_filter($events, static fn (EventPayload $event): bool => $event->traceId() === $trace_id));
    }

    /**
     * Forget every sent batch.
     */
    public function reset(): void
    {
        $this->batches = [];
    }
}
