<?php

declare(strict_types=1);

namespace AppWatch\Agent\Listeners;

use AppWatch\Agent\Enums\TelemetryTypeEnum;
use AppWatch\Agent\Payloads\EventMetadata;
use AppWatch\Agent\Payloads\RequestEventPayload;
use AppWatch\Agent\Recorder;
use AppWatch\Agent\Support\IgnoreRules;
use AppWatch\Agent\Support\TraceContext;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Records the request event of the current trace once the response is ready. Only the method,
 * route URI template, path (no query string), status, duration and peak memory are recorded —
 * never the query string, body, headers or cookies.
 */
final readonly class RecordRequestListener
{
    /**
     * The agent services the listener uses.
     */
    public function __construct(
        private Application $app,
        private Recorder $recorder,
        private TraceContext $trace_context,
        private IgnoreRules $ignore_rules,
    ) {}

    /**
     * Record the handled request.
     */
    public function handle(RequestHandled $event): void
    {
        try {
            if (! $this->recorder->isEnabled()) {
                return;
            }

            $request = $event->request;
            $response = $event->response;

            if (! $request instanceof Request || ! $response instanceof Response) {
                return;
            }

            $trace_id = $this->trace_context->traceId() ?? $this->trace_context->start(
                TelemetryTypeEnum::Request,
                ignored: $this->ignore_rules->shouldIgnorePath($request->path()),
            );

            if ($trace_id === null) {
                return;
            }

            $route = $request->route();

            $this->recorder->record(new RequestEventPayload(
                metadata: EventMetadata::capture($trace_id),
                method: $request->getMethod(),
                route: $route instanceof Route ? $route->uri() : null,
                path: '/'.ltrim($request->path(), '/'),
                status_code: $response->getStatusCode(),
                duration_ms: round($this->durationMs(), 3),
                memory_peak_bytes: memory_get_peak_usage(true),
            ));
        } catch (Throwable) {
            return;
        }
    }

    /**
     * Milliseconds since the request started: from LARAVEL_START when every request is its own
     * PHP process (php-fpm), otherwise (Octane, tests) from the start of the trace.
     */
    private function durationMs(): float
    {
        $laravel_start = defined('LARAVEL_START') ? constant('LARAVEL_START') : null;

        if (! $this->app->runningInConsole() && is_float($laravel_start)) {
            return max(0.0, (microtime(true) - $laravel_start) * 1000);
        }

        return $this->trace_context->elapsedMs() ?? 0.0;
    }
}
