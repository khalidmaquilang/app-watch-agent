<?php

declare(strict_types=1);

namespace AppWatch\Agent\Payloads;

use AppWatch\Agent\Enums\TelemetryTypeEnum;

/**
 * An HTTP request handled by the monitored app.
 */
final readonly class RequestEventPayload implements EventPayload
{
    /**
     * The request fields; `path` must not contain a query string or fragment.
     */
    public function __construct(
        public EventMetadata $metadata,
        public string $method,
        public ?string $route,
        public string $path,
        public int $status_code,
        public float $duration_ms,
        public ?int $memory_peak_bytes,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function type(): TelemetryTypeEnum
    {
        return TelemetryTypeEnum::Request;
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
            'method' => $this->method,
            'route' => $this->route,
            'path' => $this->path,
            'status_code' => $this->status_code,
            'duration_ms' => $this->duration_ms,
            'memory_peak_bytes' => $this->memory_peak_bytes,
        ];
    }
}
