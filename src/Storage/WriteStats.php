<?php

declare(strict_types=1);

namespace AppWatch\Agent\Storage;

/**
 * The outcome of writing one batch of events to the telemetry database.
 */
final readonly class WriteStats
{
    /**
     * The stored row count per event type and the events that were dropped.
     *
     * @param  array<string, int>  $stored  keyed by TelemetryTypeEnum value, every type present (0 when none)
     * @param  int  $skipped_retention  events outside [now − retention_days, now + partitions_ahead_days]
     * @param  int  $malformed  events that broke the v1 contract
     */
    public function __construct(
        public array $stored,
        public int $skipped_retention = 0,
        public int $malformed = 0,
    ) {}

    /**
     * The number of rows stored over all types.
     */
    public function storedCount(): int
    {
        return array_sum($this->stored);
    }
}
