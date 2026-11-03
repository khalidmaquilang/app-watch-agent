<?php

declare(strict_types=1);

namespace AppWatch\Agent\Support;

use AppWatch\Agent\Enums\TelemetryTypeEnum;
use Illuminate\Support\Str;
use Throwable;

/**
 * The current trace (one per request, job or console command): its uuid v7 id and whether
 * it is sampled. Starting a new trace replaces the previous one, so nothing carries over
 * between requests or jobs in long-running workers.
 */
final class TraceContext
{
    /**
     * The current trace id, null outside a trace.
     */
    private ?string $trace_id = null;

    /**
     * Whether the current trace is recorded.
     */
    private bool $sampled = true;

    /**
     * Use the sampler to decide request and job traces.
     */
    public function __construct(
        private readonly Sampler $sampler,
    ) {}

    /**
     * Start a new trace and return its id (null if it could not start). Request and job traces
     * are sampled once here, so the trace is recorded all-or-nothing; any other root (null, e.g.
     * a console command) is always sampled.
     */
    public function start(?TelemetryTypeEnum $root_type = null): ?string
    {
        try {
            $this->trace_id = Str::uuid7()->toString();
            $this->sampled = in_array($root_type, [TelemetryTypeEnum::Request, TelemetryTypeEnum::Job], true)
                ? $this->sampler->shouldSample($root_type)
                : true;

            return $this->trace_id;
        } catch (Throwable) {
            $this->end();

            return null;
        }
    }

    /**
     * End the current trace.
     */
    public function end(): void
    {
        $this->trace_id = null;
        $this->sampled = true;
    }

    /**
     * The current trace id, null outside a trace.
     */
    public function traceId(): ?string
    {
        return $this->trace_id;
    }

    /**
     * Whether a trace is in progress.
     */
    public function isActive(): bool
    {
        return $this->trace_id !== null;
    }

    /**
     * Whether the current trace is recorded (true outside a trace).
     */
    public function isSampled(): bool
    {
        return $this->sampled;
    }
}
