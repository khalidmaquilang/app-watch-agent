<?php

declare(strict_types=1);

use AppWatch\Agent\Enums\TelemetryTypeEnum;
use AppWatch\Agent\Http\Middleware\StartRequestTraceMiddleware;
use AppWatch\Agent\Payloads\EventPayload;
use AppWatch\Agent\Payloads\RequestEventPayload;
use AppWatch\Agent\Recorder;
use AppWatch\Agent\Support\TraceContext;
use AppWatch\Agent\Tests\Fixtures\Jobs\TracedJob;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    config(['app-watch.enabled' => true, 'app-watch.token' => 'environment-token', 'queue.default' => 'sync']);

    TracedJob::$trace_ids = [];
    TracedJob::$during = null;

    Route::get('/users/{user}', fn (string $user): string => "user {$user}");
    Route::get('/up', fn (): string => 'ok');

    $this->recorder = app(Recorder::class);
    $this->trace_context = app(TraceContext::class);
});

/**
 * Flush the current trace and return its request events as arrays.
 *
 * @return array<int, array<string, mixed>>
 */
function flushedRequestEvents(?string $trace_id): array
{
    $events = array_filter(
        recordedEvents((string) $trace_id),
        fn (EventPayload $event): bool => $event instanceof RequestEventPayload,
    );

    return array_values(array_map(fn (EventPayload $event): array => $event->toArray(), $events));
}

it('prepends the trace middleware to the global middleware', function (): void {
    expect(app(Kernel::class)->hasMiddleware(StartRequestTraceMiddleware::class))->toBeTrue();
});

it('records the route template and path without the query string, headers or cookies', function (): void {
    $this->withHeader('X-Api-Token', 'secret')
        ->withUnencryptedCookie('session_secret', 'secret')
        ->get('/users/5?token=secret')
        ->assertOk();

    $trace_id = $this->trace_context->traceId();
    $events = flushedRequestEvents($trace_id);

    expect($events)->toHaveCount(1)
        ->and($events[0])->toMatchArray([
            'type' => 'request',
            'trace_id' => $trace_id,
            'method' => 'GET',
            'route' => 'users/{user}',
            'path' => '/users/5',
            'status_code' => 200,
        ])
        ->and($events[0]['duration_ms'])->toBeFloat()->toBeGreaterThan(0.0)
        ->and($events[0]['memory_peak_bytes'])->toBeInt()->toBeGreaterThan(0)
        ->and(json_encode($events[0], JSON_THROW_ON_ERROR))->not->toContain('secret');
});

it('records a request without a matching route with a null route', function (): void {
    $this->get('/missing/page')->assertNotFound();

    $events = flushedRequestEvents($this->trace_context->traceId());

    expect($events)->toHaveCount(1)
        ->and($events[0]['route'])->toBeNull()
        ->and($events[0]['path'])->toBe('/missing/page')
        ->and($events[0]['status_code'])->toBe(404);
});

it('records the method and status of other requests', function (): void {
    Route::post('/orders', fn () => response('created', 201));

    $this->post('/orders', ['card' => 'secret'])->assertCreated();

    $events = flushedRequestEvents($this->trace_context->traceId());

    expect($events[0])->toMatchArray(['method' => 'POST', 'route' => 'orders', 'path' => '/orders', 'status_code' => 201])
        ->and(json_encode($events[0], JSON_THROW_ON_ERROR))->not->toContain('secret');
});

it('records the root path with a leading slash', function (): void {
    Route::get('/', fn (): string => 'home');

    $this->get('/')->assertOk();

    expect(flushedRequestEvents($this->trace_context->traceId())[0])->toMatchArray(['route' => '/', 'path' => '/']);
});

it('records nothing for an ignored path, including events inside its trace', function (): void {
    $this->get('/up')->assertOk();

    $trace_id = (string) $this->trace_context->traceId();
    $this->recorder->record(queryEvent($trace_id));

    expect($this->trace_context->isSampled())->toBeFalse()
        ->and(recordedEvents($trace_id))->toBe([]);
});

it('records nothing when the request trace is not sampled', function (): void {
    config(['app-watch.sample_rates.request' => 0]);

    $this->get('/users/5')->assertOk();

    expect(flushedRequestEvents($this->trace_context->traceId()))->toBe([]);
});

it('records nothing without a token', function (): void {
    config(['app-watch.token' => null]);
    $this->trace_context->end();

    $this->get('/users/5')->assertOk();

    expect($this->trace_context->traceId())->toBeNull();
});

it('gives every request its own trace', function (): void {
    $this->get('/users/1')->assertOk();
    $first_trace_id = $this->trace_context->traceId();

    $this->get('/users/2')->assertOk();
    $second_trace_id = $this->trace_context->traceId();

    $first_events = flushedRequestEvents($first_trace_id);
    $second_events = flushedRequestEvents($second_trace_id);

    expect($first_trace_id)->not->toBe($second_trace_id)
        ->and($first_events)->toHaveCount(1)
        ->and($first_events[0]['path'])->toBe('/users/1')
        ->and($second_events)->toHaveCount(1)
        ->and($second_events[0]['path'])->toBe('/users/2');
});

it('keeps the request trace after a job dispatched synchronously inside it', function (): void {
    Route::get('/dispatch', function (): string {
        TracedJob::dispatch();

        return 'dispatched';
    });

    $this->get('/dispatch')->assertOk();

    $request_trace_id = $this->trace_context->traceId();
    $job_trace_id = TracedJob::$trace_ids[0];
    $request_events = recordedEvents((string) $request_trace_id);
    $job_events = recordedEvents((string) $job_trace_id);

    expect($job_trace_id)->not->toBeNull()->not->toBe($request_trace_id)
        ->and(array_map(fn (EventPayload $event): TelemetryTypeEnum => $event->type(), $request_events))->toBe([TelemetryTypeEnum::Request])
        ->and(array_map(fn (EventPayload $event): TelemetryTypeEnum => $event->type(), $job_events))->toBe([TelemetryTypeEnum::Job]);
});
