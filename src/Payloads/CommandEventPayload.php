<?php

declare(strict_types=1);

namespace AppWatch\Agent\Payloads;

use AppWatch\Agent\Enums\TelemetryTypeEnum;

/**
 * An Artisan command run, with its arguments and options redacted (see RecordCommandListener).
 */
final readonly class CommandEventPayload implements EventPayload
{
    /**
     * Whether the run failed (any non-zero exit code).
     */
    public bool $failed;

    /**
     * The command fields on top of the common event metadata; `failed` follows the exit code.
     *
     * @param  array<string, mixed>  $arguments  argument and option names, each value `"?"` or `true`
     */
    public function __construct(
        public EventMetadata $metadata,
        public string $command,
        public array $arguments,
        public int $exit_code,
        public float $duration_ms,
    ) {
        $this->failed = $exit_code !== 0;
    }

    /**
     * {@inheritDoc}
     */
    public function type(): TelemetryTypeEnum
    {
        return TelemetryTypeEnum::Command;
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
            'command' => $this->command,
            'arguments' => $this->arguments,
            'exit_code' => $this->exit_code,
            'duration_ms' => $this->duration_ms,
            'failed' => $this->failed,
        ];
    }
}
