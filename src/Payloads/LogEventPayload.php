<?php

declare(strict_types=1);

namespace AppWatch\Agent\Payloads;

use AppWatch\Agent\Enums\LogLevelEnum;
use AppWatch\Agent\Enums\TelemetryTypeEnum;

/**
 * A log message written by the monitored app.
 */
final readonly class LogEventPayload implements EventPayload
{
    /**
     * The log fields on top of the common event metadata.
     *
     * @param  array<array-key, mixed>|null  $context
     */
    public function __construct(
        public EventMetadata $metadata,
        public LogLevelEnum $level,
        public string $message,
        public ?array $context,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function type(): TelemetryTypeEnum
    {
        return TelemetryTypeEnum::Log;
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
            'level' => $this->level->value,
            'message' => $this->message,
            'context' => $this->context,
        ];
    }
}
