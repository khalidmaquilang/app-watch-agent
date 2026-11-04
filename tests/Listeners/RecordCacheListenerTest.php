<?php

declare(strict_types=1);

use AppWatch\Agent\Listeners\RecordCacheListener;
use AppWatch\Agent\Payloads\CacheEventPayload;
use AppWatch\Agent\Payloads\EventPayload;
use AppWatch\Agent\Recorder;
use AppWatch\Agent\Support\TraceContext;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    config([
        'app-watch.enabled' => true,
        'cache.default' => 'array',
    ]);

    $this->trace_context = app(TraceContext::class);
    $this->trace_context->end();

    $this->recorder = app(Recorder::class);
    $this->recorder->reset();
});

/**
 * Flush the trace and return its cache events as arrays.
 *
 * @return array<int, array<string, mixed>>
 */
function flushedCacheEvents(?string $trace_id): array
{
    $events = array_filter(
        app(Recorder::class)->flush((string) $trace_id),
        fn (EventPayload $event): bool => $event instanceof CacheEventPayload,
    );

    return array_values(array_map(fn (EventPayload $event): array => $event->toArray(), $events));
}

it('records a miss, write, hit and forget without the cached value', function (): void {
    $trace_id = $this->trace_context->start();

    Cache::get('missing');
    Cache::put('k', 'v-secret-value');
    Cache::get('k');
    Cache::forget('k');

    $events = flushedCacheEvents($trace_id);

    expect(array_column($events, 'operation'))->toBe(['miss', 'write', 'hit', 'forget'])
        ->and(array_column($events, 'key'))->toBe(['missing', 'k', 'k', 'k'])
        ->and(array_column($events, 'store'))->toBe(['array', 'array', 'array', 'array'])
        ->and($events[0])->toMatchArray(['type' => 'cache', 'trace_id' => $trace_id, 'duration_ms' => null])
        ->and(json_encode($events, JSON_THROW_ON_ERROR))->not->toContain('v-secret-value');
});

it('truncates keys longer than 255 bytes', function (): void {
    $trace_id = $this->trace_context->start();

    Cache::get(str_repeat('a', 300));

    expect(flushedCacheEvents($trace_id)[0]['key'])->toBe(str_repeat('a', 255));
});

it('keeps a truncated multibyte key valid UTF-8', function (): void {
    $trace_id = $this->trace_context->start();

    Cache::get('a'.str_repeat('é', 200));

    $key = flushedCacheEvents($trace_id)[0]['key'];

    expect(strlen($key))->toBeLessThanOrEqual(255)
        ->and(mb_check_encoding($key, 'UTF-8'))->toBeTrue();
});

it('falls back to "default" when the event has no store name', function (): void {
    $trace_id = (string) $this->trace_context->start();

    event(new CacheHit(null, 'k', 'v'));

    expect(flushedCacheEvents($trace_id)[0]['store'])->toBe('default');
});

it('records nothing when cache events are not sampled', function (): void {
    config(['app-watch.sample_rates.cache' => 0]);
    $trace_id = $this->trace_context->start();

    Cache::get('k');

    expect(flushedCacheEvents($trace_id))->toBe([]);
});

it('records nothing inside an unsampled trace', function (): void {
    $trace_id = $this->trace_context->start(ignored: true);

    Cache::get('k');

    expect(flushedCacheEvents($trace_id))->toBe([]);
});

it('records nothing outside a trace', function (): void {
    Cache::get('k');

    $trace_id = (string) $this->trace_context->start();

    expect(flushedCacheEvents($trace_id))->toBe([]);
});

it('records nothing without an environment id', function (): void {
    $trace_id = $this->trace_context->start();
    config(['app-watch.environment_id' => null]);

    Cache::get('k');

    expect($this->recorder->flush((string) $trace_id))->toBe([]);
});

it('ignores cache use while it records a cache event', function (): void {
    $trace_id = (string) $this->trace_context->start();
    $listener = app(RecordCacheListener::class);

    Closure::bind(fn () => $this->is_recording = true, $listener, RecordCacheListener::class)();
    Cache::get('k');
    Closure::bind(fn () => $this->is_recording = false, $listener, RecordCacheListener::class)();
    Cache::get('after');

    expect(array_column(flushedCacheEvents($trace_id), 'key'))->toBe(['after']);
});

it('is registered as a singleton', function (): void {
    expect(app(RecordCacheListener::class))->toBe(app(RecordCacheListener::class));
});
