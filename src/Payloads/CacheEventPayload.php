<?php

declare(strict_types=1);

namespace AppWatch\Agent\Payloads;

use AppWatch\Agent\Enums\CacheOperationEnum;
use AppWatch\Agent\Enums\TelemetryTypeEnum;

/**
 * A cache hit, miss, write or forget.
 */
final readonly class CacheEventPayload implements EventPayload
{
    /**
     * The cache fields on top of the common event metadata.
     */
    public function __construct(
        public EventMetadata $metadata,
        public string $store,
        public string $key,
        public CacheOperationEnum $operation,
        public ?float $duration_ms,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function type(): TelemetryTypeEnum
    {
        return TelemetryTypeEnum::Cache;
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
            'store' => $this->store,
            'key' => $this->key,
            'operation' => $this->operation->value,
            'duration_ms' => $this->duration_ms,
        ];
    }
}
