<?php

declare(strict_types=1);

namespace AppWatch\Agent;

use AppWatch\Agent\Enums\TelemetryTypeEnum;
use AppWatch\Agent\Payloads\EventPayload;
use AppWatch\Agent\Support\IgnoreRules;
use AppWatch\Agent\Support\Sampler;
use AppWatch\Agent\Support\TraceContext;
use Illuminate\Contracts\Config\Repository;
use Throwable;

/**
 * Buffers recorded events in memory per trace until the trace is flushed. Never throws into
 * the host app: every public method swallows failures.
 */
final class Recorder
{
    /**
     * The most events kept per trace; extra events are dropped and counted.
     */
    public const int MAX_EVENTS_PER_TRACE = 5000;

    /**
     * Buffered events by trace id.
     *
     * @var array<string, array<int, EventPayload>>
     */
    private array $buffers = [];

    /**
     * Events dropped over the per-trace cap, by trace id.
     *
     * @var array<string, int>
     */
    private array $dropped_counts = [];

    /**
     * The recorder's collaborators.
     */
    public function __construct(
        private readonly Repository $config,
        private readonly TraceContext $trace_context,
        private readonly Sampler $sampler,
        private readonly IgnoreRules $ignore_rules,
    ) {}

    /**
     * Whether recording is on: `enabled` is true and a token is configured.
     */
    public function isEnabled(): bool
    {
        try {
            $token = $this->config->get('app-watch.token');

            return (bool) $this->config->get('app-watch.enabled', true) && is_string($token) && $token !== '';
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Buffer the event under its trace unless recording is off, it is not sampled or ignored,
     * or its trace is full.
     */
    public function record(EventPayload $event): void
    {
        try {
            if (! $this->isEnabled() || ! $this->shouldRecord($event)) {
                return;
            }

            $trace_id = $event->traceId();

            if (count($this->buffers[$trace_id] ?? []) >= self::MAX_EVENTS_PER_TRACE) {
                $this->dropped_counts[$trace_id] = ($this->dropped_counts[$trace_id] ?? 0) + 1;

                return;
            }

            $this->buffers[$trace_id][] = $event;
        } catch (Throwable) {
            return;
        }
    }

    /**
     * Return the trace's buffered events and forget them (and its drop count).
     *
     * @return array<int, EventPayload>
     */
    public function flush(string $trace_id): array
    {
        $events = $this->buffers[$trace_id] ?? [];

        unset($this->buffers[$trace_id], $this->dropped_counts[$trace_id]);

        return $events;
    }

    /**
     * How many of the trace's events were dropped over the per-trace cap since its last flush.
     */
    public function droppedCount(string $trace_id): int
    {
        return $this->dropped_counts[$trace_id] ?? 0;
    }

    /**
     * Forget every buffered event and drop count (e.g. between Octane requests).
     */
    public function reset(): void
    {
        $this->buffers = [];
        $this->dropped_counts = [];
    }

    /**
     * Apply sampling and the ignore rules. Exceptions are always sampled, even in an unsampled
     * trace; events of the current unsampled trace are dropped; request and job events of the
     * current trace follow the trace decision; everything else is sampled per type.
     */
    private function shouldRecord(EventPayload $event): bool
    {
        $type = $event->type();

        if ($type !== TelemetryTypeEnum::Exception) {
            $is_current_trace = $this->trace_context->traceId() === $event->traceId();

            if ($is_current_trace && ! $this->trace_context->isSampled()) {
                return false;
            }

            $is_trace_root = in_array($type, [TelemetryTypeEnum::Request, TelemetryTypeEnum::Job], true);

            if (! ($is_current_trace && $is_trace_root) && ! $this->sampler->shouldSample($type)) {
                return false;
            }
        }

        return ! $this->ignore_rules->shouldIgnore($event);
    }
}
