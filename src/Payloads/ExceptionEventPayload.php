<?php

declare(strict_types=1);

namespace AppWatch\Agent\Payloads;

use AppWatch\Agent\Enums\TelemetryTypeEnum;

/**
 * An exception reported by the monitored app.
 */
final readonly class ExceptionEventPayload implements EventPayload
{
    /**
     * The most stack frames the v1 contract accepts per exception; extra frames are cut off.
     */
    public const int MAX_TRACE_FRAMES = 100;

    /**
     * The exception fields on top of the common event metadata.
     *
     * @param  array<int, ExceptionTraceFrame>  $trace
     */
    public function __construct(
        public EventMetadata $metadata,
        public string $class,
        public string $message,
        public string $file,
        public int $line,
        public array $trace,
        public bool $handled,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function type(): TelemetryTypeEnum
    {
        return TelemetryTypeEnum::Exception;
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
            'class' => $this->class,
            'message' => $this->message,
            'file' => $this->file,
            'line' => $this->line,
            'trace' => array_map(
                static fn (ExceptionTraceFrame $frame): array => $frame->toArray(),
                array_slice(array_values($this->trace), 0, self::MAX_TRACE_FRAMES),
            ),
            'handled' => $this->handled,
        ];
    }
}
