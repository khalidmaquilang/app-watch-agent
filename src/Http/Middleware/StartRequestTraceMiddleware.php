<?php

declare(strict_types=1);

namespace AppWatch\Agent\Http\Middleware;

use AppWatch\Agent\Enums\TelemetryTypeEnum;
use AppWatch\Agent\Recorder;
use AppWatch\Agent\Support\IgnoreRules;
use AppWatch\Agent\Support\TraceContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Starts a new trace for every HTTP request. Prepended to the global middleware stack, so the
 * trace covers all other middleware. A request to an ignored path gets an unsampled trace, so
 * nothing it does is recorded.
 */
final readonly class StartRequestTraceMiddleware
{
    /**
     * The agent services the middleware uses.
     */
    public function __construct(
        private Recorder $recorder,
        private TraceContext $trace_context,
        private IgnoreRules $ignore_rules,
    ) {}

    /**
     * Start the request's trace, then pass the request on.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            if ($this->recorder->isEnabled()) {
                $this->trace_context->start(
                    TelemetryTypeEnum::Request,
                    ignored: $this->ignore_rules->shouldIgnorePath($request->path()),
                );
            }
        } catch (Throwable) {
            // Never break the host app's request.
        }

        return $next($request);
    }
}
