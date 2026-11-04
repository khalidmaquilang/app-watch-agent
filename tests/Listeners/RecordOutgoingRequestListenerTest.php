<?php

declare(strict_types=1);

use AppWatch\Agent\Listeners\RecordOutgoingRequestListener;
use AppWatch\Agent\Payloads\EventPayload;
use AppWatch\Agent\Payloads\OutgoingRequestEventPayload;
use AppWatch\Agent\Recorder;
use AppWatch\Agent\Support\TraceContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['app-watch.enabled' => true]);

    $this->recorder = app(Recorder::class);
    $this->trace_context = app(TraceContext::class);
    $this->trace_id = (string) $this->trace_context->start();
});

/**
 * Flush the trace and return its outgoing request events as arrays.
 *
 * @return array<int, array<string, mixed>>
 */
function flushedOutgoingRequestEvents(string $trace_id): array
{
    $events = array_filter(
        app(Recorder::class)->flush($trace_id),
        fn (EventPayload $event): bool => $event instanceof OutgoingRequestEventPayload,
    );

    return array_values(array_map(fn (EventPayload $event): array => $event->toArray(), $events));
}

it('records the host and the URL without userinfo or query string', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    Http::get('https://user:pass@api.example.com/v1/items?key=secret');

    $events = flushedOutgoingRequestEvents($this->trace_id);

    expect($events)->toHaveCount(1)
        ->and($events[0])->toMatchArray([
            'type' => 'outgoing_request',
            'trace_id' => $this->trace_id,
            'method' => 'GET',
            'target_host' => 'api.example.com',
            'url' => 'https://api.example.com/v1/items',
            'status_code' => 200,
            'failed' => false,
        ])
        ->and($events[0]['duration_ms'])->toBeFloat()->toBeGreaterThan(0.0)
        ->and(json_encode($events[0], JSON_THROW_ON_ERROR))->not->toContain('pass')->not->toContain('secret')->not->toContain('user');
});

it('records the method, a non-default port and a bare host without headers or body', function (): void {
    Http::fake(['*' => Http::response('created', 201)]);

    Http::withToken('secret-token')->post('http://api.example.com:8080#fragment', ['card' => 'secret']);

    expect(flushedOutgoingRequestEvents($this->trace_id)[0])->toMatchArray([
        'method' => 'POST',
        'target_host' => 'api.example.com',
        'url' => 'http://api.example.com:8080/',
        'status_code' => 201,
        'failed' => false,
    ]);
});

it('records a connection failure with a null status as failed', function (): void {
    Http::fake(['*' => Http::failedConnection()]);

    expect(fn () => Http::get('https://down.example.com/health?key=secret'))->toThrow(ConnectionException::class);

    $events = flushedOutgoingRequestEvents($this->trace_id);

    expect($events)->toHaveCount(1)
        ->and($events[0])->toMatchArray([
            'target_host' => 'down.example.com',
            'url' => 'https://down.example.com/health',
            'status_code' => null,
            'failed' => true,
        ])
        ->and(json_encode($events[0], JSON_THROW_ON_ERROR))->not->toContain('secret');
});

it('records a 5xx response as failed and a 4xx response as not failed', function (int $status, bool $failed): void {
    Http::fake(['*' => Http::response('', $status)]);

    Http::get('https://api.example.com/v1/items');

    expect(flushedOutgoingRequestEvents($this->trace_id)[0])->toMatchArray(['status_code' => $status, 'failed' => $failed]);
})->with([
    '503' => [503, true],
    '500' => [500, true],
    '404' => [404, false],
]);

it('records nothing outside a trace', function (): void {
    $this->trace_context->end();
    Http::fake(['*' => Http::response('', 200)]);

    Http::get('https://api.example.com/v1/items');

    expect($this->trace_context->traceId())->toBeNull();
});

it('records nothing inside an unsampled trace', function (): void {
    $trace_id = (string) $this->trace_context->start(ignored: true);
    Http::fake(['*' => Http::response('', 200)]);

    Http::get('https://api.example.com/v1/items');

    expect(flushedOutgoingRequestEvents($trace_id))->toBe([]);
});

it('records nothing when outgoing requests are not sampled', function (): void {
    config(['app-watch.sample_rates.outgoing_request' => 0]);
    Http::fake(['*' => Http::response('', 200)]);

    Http::get('https://api.example.com/v1/items');

    expect(flushedOutgoingRequestEvents($this->trace_id))->toBe([]);
});

it('records nothing without an environment id', function (): void {
    config(['app-watch.environment_id' => null]);
    Http::fake(['*' => Http::response('', 200)]);

    Http::get('https://api.example.com/v1/items');

    expect($this->recorder->flush($this->trace_id))->toBe([]);
});

it('records every retry attempt', function (): void {
    Http::fake(['*' => Http::sequence()->push('', 503)->push('', 200)]);

    Http::retry(2, 0, throw: false)->get('https://api.example.com/v1/items');

    expect(array_column(flushedOutgoingRequestEvents($this->trace_id), 'status_code'))->toBe([503, 200]);
});

it('is registered as a singleton', function (): void {
    expect(app(RecordOutgoingRequestListener::class))->toBe(app(RecordOutgoingRequestListener::class));
});
