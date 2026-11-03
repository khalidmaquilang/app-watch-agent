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
        public ?string $spool_file = null,
    ) {}

    /**
     * The same outcome, for the replay of the given spooled batch file.
     */
    public function forSpoolFile(string $spool_file): self
    {
        return new self(
            delivered_count: $this->delivered_count,
            rejected_count: $this->rejected_count,
            rejected_status: $this->rejected_status,
            pending: $this->pending,
            retry_after_seconds: $this->retry_after_seconds,
            failure: $this->failure,
            spool_file: $spool_file,
        );
    }

    /**
     * Whether some events could not be delivered yet and should be retried.
     */
    public function shouldRetry(): bool
    {
        return $this->pending !== [];
    }
}
