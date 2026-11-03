<?php

declare(strict_types=1);

namespace AppWatch\Agent\Support;

use AppWatch\Agent\Enums\TelemetryTypeEnum;
use Illuminate\Support\Str;
use Throwable;

/**
 * The current trace (one per request, job or console command): its uuid v7 id, whether it is
 * sampled and when it started. Starting a new trace replaces the previous one, so nothing carries
 * over between requests or jobs in long-running workers. A nested trace (a job run inside a
 * request, command or other job, e.g. on the sync queue) remembers its parent and restores it
 * when it ends, so the parent's later events keep their own trace id.
 */
final class TraceContext
{
    /**
     * The most parent traces kept for nested traces; the oldest is forgotten beyond this.
     */
    public const int MAX_NESTING_DEPTH = 16;

    /**
     * The current trace id, null outside a trace.
     */
    private ?string $trace_id = null;

    /**
     * Whether the current trace is recorded.
     */
    private bool $sampled = true;

    /**
     * When the current trace started (hrtime in nanoseconds), null outside a trace.
     */
    private ?int $started_at = null;

    /**
     * The traces suspended by nested traces, innermost last.
     *
     * @var array<int, array{trace_id: string|null, sampled: bool, started_at: int|null}>
     */
    private array $parents = [];

    /**
     * Use the sampler to decide request and job traces.
     */
    public function __construct(
        private readonly Sampler $sampler,
    ) {}

    /**
     * Start a new trace, forgetting any current or suspended trace, and return its id (null if it
     * could not start). Request and job traces are sampled once here, so the trace is recorded
     * all-or-nothing; any other root (null, e.g. a console command) is always sampled. An ignored
     * trace (e.g. an ignored path) is never sampled.
     */
    public function start(?TelemetryTypeEnum $root_type = null, bool $ignored = false): ?string
    {
        $this->parents = [];

        return $this->begin($root_type, $ignored);
    }

    /**
     * Suspend the current trace (if any) and start a new one inside it; endNested() restores it.
     */
    public function startNested(?TelemetryTypeEnum $root_type = null, bool $ignored = false): ?string
    {
        $this->parents[] = [
            'trace_id' => $this->trace_id,
            'sampled' => $this->sampled,
            'started_at' => $this->started_at,
        ];

        if (count($this->parents) > self::MAX_NESTING_DEPTH) {
            array_shift($this->parents);
        }

        return $this->begin($root_type, $ignored);
    }

    /**
     * End the current nested trace and restore the trace it suspended (or no trace).
     */
    public function endNested(): void
    {
        $parent = array_pop($this->parents);

        if ($parent === null) {
            $this->end();

            return;
        }

        $this->trace_id = $parent['trace_id'];
        $this->sampled = $parent['sampled'];
        $this->started_at = $parent['started_at'];
    }

    /**
     * End the current trace and forget every suspended one.
     */
    public function end(): void
    {
        $this->parents = [];
        $this->clearCurrent();
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

    /**
     * When the current trace started (hrtime in nanoseconds), null outside a trace.
     */
    public function startedAt(): ?int
    {
        return $this->started_at;
    }

    /**
     * Milliseconds since the current trace started, null outside a trace.
     */
    public function elapsedMs(): ?float
    {
        if ($this->started_at === null) {
            return null;
        }

        return max(0.0, ((int) hrtime(true) - $this->started_at) / 1_000_000);
    }

    /**
     * Make a new trace current and decide its sampling.
     */
    private function begin(?TelemetryTypeEnum $root_type, bool $ignored): ?string
    {
        try {
            $this->trace_id = Str::uuid7()->toString();
            $this->started_at = (int) hrtime(true);
            $this->sampled = match (true) {
                $ignored => false,
                in_array($root_type, [TelemetryTypeEnum::Request, TelemetryTypeEnum::Job], true) => $this->sampler->shouldSample($root_type),
                default => true,
            };

            return $this->trace_id;
        } catch (Throwable) {
            $this->clearCurrent();

            return null;
        }
    }

    /**
     * Forget the current trace only.
     */
    private function clearCurrent(): void
    {
        $this->trace_id = null;
        $this->sampled = true;
        $this->started_at = null;
    }
}
