<?php

declare(strict_types=1);

namespace AppWatch\Agent\Listeners;

use AppWatch\Agent\Payloads\EventMetadata;
use AppWatch\Agent\Payloads\OutgoingRequestEventPayload;
use AppWatch\Agent\Recorder;
use AppWatch\Agent\Support\TraceContext;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Psr\Http\Message\UriInterface;
use Throwable;

/**
 * Records the HTTP client requests of the current trace: RequestSending notes when a request
 * started, and ResponseReceived / ConnectionFailed record it. Only the method, target host, URL
 * without userinfo, query string or fragment, status and duration are recorded — never headers or
 * bodies. Requests outside a trace are not recorded.
 * Bound as a singleton, because the start times are kept between those events.
 */
final class RecordOutgoingRequestListener
{
    /**
     * The most requests whose start time is kept at once; the oldest is forgotten beyond this.
     */
    public const int MAX_TRACKED_REQUESTS = 100;

    /**
     * The longest URL kept (the v1 contract limit).
     */
    public const int MAX_URL_LENGTH = 2048;

    /**
     * Start times (hrtime in nanoseconds) of requests in flight, oldest first, by "METHOD uri".
     *
     * @var array<string, array<int, int>>
     */
    private array $started_at = [];

    /**
     * The agent services the listener uses.
     */
    public function __construct(
        private readonly Recorder $recorder,
        private readonly TraceContext $trace_context,
    ) {}

    /**
     * Note when the request started.
     */
    public function handleRequestSending(RequestSending $event): void
    {
        try {
            if (! $this->shouldRecord()) {
                return;
            }

            $this->started_at[$this->requestKey($event->request)][] = (int) hrtime(true);

            if (count($this->started_at) > self::MAX_TRACKED_REQUESTS) {
                array_shift($this->started_at);
            }
        } catch (Throwable) {
            return;
        }
    }

    /**
     * Record a request that got a response; a 5xx response counts as failed.
     */
    public function handleResponseReceived(ResponseReceived $event): void
    {
        try {
            $status_code = $event->response->status();
            $transfer_seconds = $event->response->transferStats?->getTransferTime();

            $this->record(
                $event->request,
                $status_code,
                $status_code >= 500,
                is_float($transfer_seconds) ? $transfer_seconds * 1000 : null,
            );
        } catch (Throwable) {
            return;
        }
    }

    /**
     * Record a request that never got a response.
     */
    public function handleConnectionFailed(ConnectionFailed $event): void
    {
        $this->record($event->request, null, true, null);
    }

    /**
     * Record the request under the current trace.
     */
    private function record(Request $request, ?int $status_code, bool $failed, ?float $fallback_duration_ms): void
    {
        try {
            $duration_ms = $this->takeDurationMs($request) ?? $fallback_duration_ms ?? 0.0;
            $trace_id = $this->trace_context->traceId();

            if ($trace_id === null || ! $this->shouldRecord()) {
                return;
            }

            $uri = $request->toPsrRequest()->getUri();

            $this->recorder->record(new OutgoingRequestEventPayload(
                metadata: EventMetadata::capture($trace_id),
                method: substr(strtoupper($request->method()), 0, 16),
                target_host: substr($uri->getHost(), 0, 255),
                url: substr($this->sanitizedUrl($uri), 0, self::MAX_URL_LENGTH),
                status_code: $status_code !== null && $status_code >= 100 && $status_code <= 599 ? $status_code : null,
                failed: $failed,
                duration_ms: round(max(0.0, $duration_ms), 3),
            ));
        } catch (Throwable) {
            return;
        }
    }

    /**
     * Whether the request is recorded: recording is on and a sampled trace is active.
     */
    private function shouldRecord(): bool
    {
        return $this->trace_context->isActive()
            && $this->trace_context->isSampled()
            && $this->recorder->isEnabled();
    }

    /**
     * The URL with only its scheme, host, port and path.
     */
    private function sanitizedUrl(UriInterface $uri): string
    {
        $port = $uri->getPort();
        $path = $uri->getPath();

        return $uri->getScheme().'://'.$uri->getHost()
            .($port !== null ? ':'.$port : '')
            .($path === '' ? '/' : '/'.ltrim($path, '/'));
    }

    /**
     * Milliseconds since the request's tracked start, forgetting it; null if its start is unknown.
     */
    private function takeDurationMs(Request $request): ?float
    {
        $key = $this->requestKey($request);
        $start_times = $this->started_at[$key] ?? [];
        $started_at = array_shift($start_times);

        if ($start_times === []) {
            unset($this->started_at[$key]);
        } else {
            $this->started_at[$key] = $start_times;
        }

        return $started_at !== null ? ((int) hrtime(true) - $started_at) / 1_000_000 : null;
    }

    /**
     * The key a request's start time is kept under (the same for its later events).
     */
    private function requestKey(Request $request): string
    {
        return strtoupper($request->method()).' '.$request->toPsrRequest()->getUri();
    }
}
