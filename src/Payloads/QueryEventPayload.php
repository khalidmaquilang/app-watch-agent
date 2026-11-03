<?php

declare(strict_types=1);

namespace AppWatch\Agent\Payloads;

use AppWatch\Agent\Enums\TelemetryTypeEnum;

/**
 * A database query run by the monitored app.
 */
final readonly class QueryEventPayload implements EventPayload
{
    /**
     * The query fields on top of the common event metadata.
     */
    public function __construct(
        public EventMetadata $metadata,
        public string $connection,
        public string $sql,
        public string $sql_hash,
        public float $duration_ms,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function type(): TelemetryTypeEnum
    {
        return TelemetryTypeEnum::Query;
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
            'connection' => $this->connection,
            'sql' => $this->sql,
            'sql_hash' => $this->sql_hash,
            'duration_ms' => $this->duration_ms,
        ];
    }
}
