<?php

declare(strict_types=1);

namespace AppWatch\Agent\Payloads;

use AppWatch\Agent\Enums\JobStatusEnum;
use AppWatch\Agent\Enums\TelemetryTypeEnum;

/**
 * A queued job run (processed, failed or released).
 */
final readonly class JobEventPayload implements EventPayload
{
    /**
     * The job fields on top of the common event metadata.
     */
    public function __construct(
        public EventMetadata $metadata,
        public string $job_class,
        public string $queue,
        public string $connection,
        public JobStatusEnum $status,
        public int $attempt,
        public float $duration_ms,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function type(): TelemetryTypeEnum
    {
        return TelemetryTypeEnum::Job;
    }

    /**
     * {@inheritDoc}
     */
    public function traceId(): string
    {
        return $this->metadata->trace_id;
    }

    /**
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type()->value,
            ...$this->metadata->toArray(),
            'job_class' => $this->job_class,
            'queue' => $this->queue,
            'connection' => $this->connection,
            'status' => $this->status->value,
            'attempt' => $this->attempt,
            'duration_ms' => $this->duration_ms,
        ];
    }
}
