<?php

declare(strict_types=1);

namespace AppWatch\Agent\Tests\Fixtures\Jobs;

use AppWatch\Agent\Support\TraceContext;
use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * A test job that remembers the trace it ran in, runs an optional callback and can throw.
 */
class TracedJob implements ShouldQueue
{
    use Queueable;

    /**
     * The trace ids the job ran in, in order.
     *
     * @var array<int, string|null>
     */
    public static array $trace_ids = [];

    /**
     * Run inside handle(), with the job's trace id (static, because jobs are serialized).
     */
    public static ?Closure $during = null;

    /**
     * Whether handle() throws.
     */
    public function __construct(
        public bool $should_throw = false,
    ) {}

    /**
     * Remember the trace, run the callback and throw if asked.
     */
    public function handle(TraceContext $trace_context): void
    {
        $trace_id = $trace_context->traceId();
        self::$trace_ids[] = $trace_id;

        if (self::$during !== null) {
            (self::$during)($trace_id);
        }

        if ($this->should_throw) {
            throw new RuntimeException('Job failed on purpose.');
        }
    }
}
