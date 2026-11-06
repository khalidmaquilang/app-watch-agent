<?php

declare(strict_types=1);

namespace AppWatch\Agent\Payloads;

use AppWatch\Agent\Enums\ScheduledTaskKindEnum;
use AppWatch\Agent\Enums\ScheduledTaskOutcomeEnum;
use AppWatch\Agent\Enums\TelemetryTypeEnum;

/**
 * A scheduled task run (see RecordScheduledTaskListener): what ran, its cron expression, how it
 * ended and how long it took.
 */
final readonly class ScheduledTaskEventPayload implements EventPayload
{
    /**
     * The scheduled task fields on top of the common event metadata.
     *
     * @param  string  $task  the command with redacted arguments, the job class, the closure's description, or the redacted shell command
     * @param  float  $duration_ms  0 for a skipped run
     * @param  int|null  $exit_code  null when the run did not report one (skipped, or started in the background)
     */
    public function __construct(
        public EventMetadata $metadata,
        public string $task,
        public ScheduledTaskKindEnum $kind,
        public string $expression,
        public ?string $timezone,
        public ScheduledTaskOutcomeEnum $outcome,
        public float $duration_ms,
        public ?int $exit_code,
        public bool $without_overlapping,
        public bool $on_one_server,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function type(): TelemetryTypeEnum
    {
        return TelemetryTypeEnum::ScheduledTask;
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
            'task' => $this->task,
            'kind' => $this->kind->value,
            'expression' => $this->expression,
            'timezone' => $this->timezone,
            'outcome' => $this->outcome->value,
            'duration_ms' => $this->duration_ms,
            'exit_code' => $this->exit_code,
            'without_overlapping' => $this->without_overlapping,
            'on_one_server' => $this->on_one_server,
        ];
    }
}
