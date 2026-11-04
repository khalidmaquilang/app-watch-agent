<?php

declare(strict_types=1);

namespace AppWatch\Agent\Daemon;

/**
 * The outcome of handing events to the telemetry database: how many rows were stored, how many
 * events were dropped by the writer (malformed or outside the retention window), how many the
 * database rejected for good (poison, with the SQLSTATE of the last rejection), and the events
 * still to be retried with the sanitised reason they failed.
 */
final readonly class WriteResult
{
    /**
     * The outcome's counts and pending events.
     *
     * @param  array<int, array<string, mixed>>  $pending
     */
    public function __construct(
        public int $stored_count = 0,
        public int $skipped_count = 0,
        public int $poison_count = 0,
        public ?string $poison_sqlstate = null,
        public array $pending = [],
        public ?string $failure = null,
        public ?string $spool_file = null,
    ) {}

    /**
     * The same outcome, for the replay of the given spooled batch file.
     */
    public function forSpoolFile(string $spool_file): self
    {
        return new self(
            stored_count: $this->stored_count,
            skipped_count: $this->skipped_count,
            poison_count: $this->poison_count,
            poison_sqlstate: $this->poison_sqlstate,
            pending: $this->pending,
            failure: $this->failure,
            spool_file: $spool_file,
        );
    }

    /**
     * Whether some events could not be written yet and should be retried.
     */
    public function shouldRetry(): bool
    {
        return $this->pending !== [];
    }

    /**
     * Whether the database rejected events for good.
     */
    public function isPoison(): bool
    {
        return $this->poison_count > 0;
    }

    /**
     * Whether the last rejection was a missing partition (SQLSTATE 23514, "no partition of
     * relation found for row"): the partition upkeep (`app-watch:partitions`) is not running.
     */
    public function isMissingPartition(): bool
    {
        return $this->poison_sqlstate === DatabaseWriter::CHECK_VIOLATION;
    }
}
