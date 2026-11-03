<?php

declare(strict_types=1);

namespace AppWatch\Agent\Daemon;

/**
 * The outcome of handing events to the monitor: how many were delivered, how many the monitor
 * rejected for good (and with which status), and the events still to be retried (with the
 * monitor's Retry-After, if it sent one).
 */
final readonly class IngestResult
{
    /**
     * The outcome's counts and pending events.
     *
     * @param  array<int, array<string, mixed>>  $pending
     */
    public function __construct(
        public int $delivered_count = 0,
        public int $rejected_count = 0,
        public ?int $rejected_status = null,
        public array $pending = [],
        public ?int $retry_after_seconds = null,
        public ?string $failure = null,
    ) {}

    /**
     * Whether some events could not be delivered yet and should be retried.
     */
    public function shouldRetry(): bool
    {
        return $this->pending !== [];
    }
}
