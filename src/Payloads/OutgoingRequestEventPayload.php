<?php

declare(strict_types=1);

namespace AppWatch\Agent\Payloads;

use AppWatch\Agent\Enums\TelemetryTypeEnum;

/**
 * An outgoing HTTP request made by the monitored app; `target_host` is the remote host.
 */
final readonly class OutgoingRequestEventPayload implements EventPayload
{
    /**
     * The outgoing request fields on top of the common event metadata.
     */
    public function __construct(
        public EventMetadata $metadata,
        public string $method,
        public string $target_host,
        public string $url,
        public ?int $status_code,
        public bool $failed,
        public float $duration_ms,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function type(): TelemetryTypeEnum
    {
        return TelemetryTypeEnum::OutgoingRequest;
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
            'target_host' => $this->target_host,
            'url' => $this->url,
            'status_code' => $this->status_code,
            'failed' => $this->failed,
            'duration_ms' => $this->duration_ms,
        ];
    }
}
