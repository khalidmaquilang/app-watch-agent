<?php

declare(strict_types=1);

namespace AppWatch\Agent\Support;

use AppWatch\Agent\Enums\TelemetryTypeEnum;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;
use Throwable;

/**
 * The current trace (one per request, job or console command): its uuid v7 id, whether it is
 * sampled, when it started and the app's default database connection at that moment. Starting a new trace replaces the previous one, so nothing carries
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
     * The environment variable a parent process (the scheduler) passes its trace id in, so a child
     * process (the scheduled command) joins that trace instead of starting its own.
     */
    public const string PARENT_TRACE_ID_ENV = 'APP_WATCH_PARENT_TRACE_ID';

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
     * The app's default database connection (`database.default`) when the current trace started,
     * null outside a trace. Kept so a temporary switch during the trace (e.g. `migrate --database=`)
     * does not change which connection counts as the app's own.
     */
    private ?string $default_connection = null;

    /**
     * The traces suspended by nested traces, innermost last.
     *
     * @var array<int, array{trace_id: string|null, sampled: bool, started_at: int|null, default_connection: string|null}>
     */
    private array $parents = [];

    /**
     * Use the sampler to decide request and job traces and the config to read the default connection.
     */
    public function __construct(
        private readonly Sampler $sampler,
        private readonly Repository $config,
    ) {}

    /**
     * Start a new trace, forgetting any current or suspended trace, and return its id (null if it
     * could not start). Request and job traces are sampled once here, so the trace is recorded
     * all-or-nothing; any other root (null, e.g. a console command) is always sampled. An ignored
     * trace (e.g. an ignored path) is never sampled. A well-formed trace id handed in by a parent
     * process (PARENT_TRACE_ID_ENV) becomes the new trace's id; the variable is cleared either way,
     * so it reaches neither a later trace nor this process's own children.
     */
    public function start(?TelemetryTypeEnum $root_type = null, bool $ignored = false): ?string
    {
        $this->parents = [];

        return $this->begin($root_type, $ignored, self::takeParentTraceId());
    }

    /**
     * Whether the value is a trace id this agent generates: a lowercase uuid v7.
     *
     * @phpstan-assert-if-true string $value
     */
    public static function isValidTraceId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value) === 1;
    }

    /**
     * Hand the trace id to the child processes this process starts from now on (Symfony Process
     * inherits variables that are in both getenv() and $_SERVER).
     */
    public static function exportParentTraceId(string $trace_id): void
    {
        putenv(self::PARENT_TRACE_ID_ENV.'='.$trace_id);
        $_SERVER[self::PARENT_TRACE_ID_ENV] = $trace_id;
        $_ENV[self::PARENT_TRACE_ID_ENV] = $trace_id;
    }

    /**
     * Stop handing a trace id to child processes.
     */
    public static function clearParentTraceId(): void
    {
        putenv(self::PARENT_TRACE_ID_ENV);
        unset($_SERVER[self::PARENT_TRACE_ID_ENV], $_ENV[self::PARENT_TRACE_ID_ENV]);
    }

    /**
     * The trace id handed in by the parent process if it is well-formed (else null), clearing it.
     */
    private static function takeParentTraceId(): ?string
    {
        $trace_id = getenv(self::PARENT_TRACE_ID_ENV);

        if ($trace_id === false) {
            $trace_id = $_SERVER[self::PARENT_TRACE_ID_ENV] ?? $_ENV[self::PARENT_TRACE_ID_ENV] ?? null;
        }

        if ($trace_id === null) {
            return null;
        }

        self::clearParentTraceId();

        return self::isValidTraceId($trace_id) ? $trace_id : null;
    }

    /**
     * Suspend the current trace (if any) and start a new one inside it; endNested() restores it.
     * The new trace gets a fresh id; a parent process's id (PARENT_TRACE_ID_ENV) is left alone.
     */
    public function startNested(?TelemetryTypeEnum $root_type = null, bool $ignored = false): ?string
    {
        $this->parents[] = [
            'trace_id' => $this->trace_id,
            'sampled' => $this->sampled,
            'started_at' => $this->started_at,
            'default_connection' => $this->default_connection,
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
        $this->default_connection = $parent['default_connection'];
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
     * The app's default database connection when the current trace started, null outside a trace.
     */
    public function defaultConnection(): ?string
    {
        return $this->default_connection;
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
     * Make a new trace current (with the given id, else a new uuid v7) and decide its sampling.
     */
    private function begin(?TelemetryTypeEnum $root_type, bool $ignored, ?string $trace_id = null): ?string
    {
        try {
            $this->trace_id = $trace_id ?? Str::uuid7()->toString();
            $this->started_at = (int) hrtime(true);
            $default_connection = $this->config->get('database.default');
            $this->default_connection = is_string($default_connection) ? $default_connection : null;
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
        $this->default_connection = null;
    }
}
