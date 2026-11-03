<?php

declare(strict_types=1);

use AppWatch\Agent\Listeners\RecordLogListener;
use AppWatch\Agent\Payloads\EventPayload;
use AppWatch\Agent\Payloads\LogEventPayload;
use AppWatch\Agent\Recorder;
use AppWatch\Agent\Support\TraceContext;
use AppWatch\Agent\Tests\Fixtures\LoggingContextValue;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    config([
        'app-watch.enabled' => true,
        'app-watch.token' => 'environment-token',
        'app-watch.log_level' => 'warning',
        'logging.default' => 'null',
    ]);

    $this->trace_context = app(TraceContext::class);
    $this->trace_context->end();

    $this->recorder = app(Recorder::class);
    $this->recorder->reset();
});

/**
 * Flush the trace and return its log events as arrays.
 *
 * @return array<int, array<string, mixed>>
 */
function flushedLogEvents(?string $trace_id): array
{
    $events = array_filter(
        app(Recorder::class)->flush((string) $trace_id),
        fn (EventPayload $event): bool => $event instanceof LogEventPayload,
    );

    return array_values(array_map(fn (EventPayload $event): array => $event->toArray(), $events));
}

it('records an error with its sensitive context values redacted', function (): void {
    $trace_id = $this->trace_context->start();

    Log::info('Just so you know');
    Log::error('Payment failed', ['password' => 'x', 'nested' => ['token' => 'y'], 'order_id' => 42]);

    $events = flushedLogEvents($trace_id);

    expect($events)->toHaveCount(1)
        ->and($events[0])->toMatchArray([
            'type' => 'log',
            'trace_id' => $trace_id,
            'level' => 'error',
            'message' => 'Payment failed',
            'context' => ['password' => '[redacted]', 'nested' => ['token' => '[redacted]'], 'order_id' => 42],
        ]);
});

it('records levels at or above the threshold only', function (string $threshold, array $expected_levels): void {
    config(['app-watch.log_level' => $threshold]);
    $trace_id = $this->trace_context->start();

    foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'] as $level) {
        Log::log($level, 'A '.$level.' message');
    }

    expect(array_column(flushedLogEvents($trace_id), 'level'))->toBe($expected_levels);
})->with([
    'warning (default)' => ['warning', ['warning', 'error', 'critical', 'alert', 'emergency']],
    'debug' => ['debug', ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency']],
    'critical' => ['critical', ['critical', 'alert', 'emergency']],
    'unknown means warning' => ['loud', ['warning', 'error', 'critical', 'alert', 'emergency']],
]);

it('redacts sensitive keys case-insensitively at any depth', function (array $context, array $expected): void {
    $trace_id = $this->trace_context->start();

    Log::error('Redaction', $context);

    expect(flushedLogEvents($trace_id)[0]['context'])->toBe($expected);
})->with([
    'password' => [['password' => 'x'], ['password' => '[redacted]']],
    'upper case' => [['PASSWORD' => 'x', 'Token' => 'y'], ['PASSWORD' => '[redacted]', 'Token' => '[redacted]']],
    'secret' => [['client_secret' => 'x'], ['client_secret' => '[redacted]']],
    'authorization header' => [['headers' => ['Authorization' => 'Bearer abc']], ['headers' => ['Authorization' => '[redacted]']]],
    'cookie' => [['Cookie' => 'session=abc'], ['Cookie' => '[redacted]']],
    'api key with dashes' => [['X-Api-Key' => 'abc', 'api_key' => 'def'], ['X-Api-Key' => '[redacted]', 'api_key' => '[redacted]']],
    'access token' => [['access_token' => 'abc'], ['access_token' => '[redacted]']],
    'deeply nested' => [['a' => ['b' => ['c' => ['password' => 'x', 'kept' => 1]]]], ['a' => ['b' => ['c' => ['password' => '[redacted]', 'kept' => 1]]]]],
    'array under a sensitive key' => [['tokens' => ['a', 'b']], ['tokens' => '[redacted]']],
    'list values are kept' => [['ids' => [1, 2, 3]], ['ids' => [1, 2, 3]]],
    'non-sensitive keys are kept' => [['user_id' => 5, 'email' => 'a@example.com'], ['user_id' => 5, 'email' => 'a@example.com']],
]);

it('reduces an exception in the context to its class and message', function (): void {
    $trace_id = $this->trace_context->start();

    Log::error('Failed', ['exception' => new RuntimeException('boom')]);

    expect(flushedLogEvents($trace_id)[0]['context'])->toBe([
        'exception' => ['class' => RuntimeException::class, 'message' => 'boom'],
    ]);
});

it('turns other context objects into JSON-safe values', function (): void {
    $trace_id = $this->trace_context->start();

    Log::error('Objects', [
        'date' => new DateTimeImmutable('2026-11-03T10:00:00+00:00'),
        'object' => new stdClass,
        'nan' => NAN,
        'infinite' => -INF,
        'invalid_utf8' => "bad \xB1 byte",
    ]);

    $context = flushedLogEvents($trace_id)[0]['context'];

    expect($context)->toMatchArray([
        'date' => '2026-11-03T10:00:00+00:00',
        'object' => '[object stdClass]',
        'nan' => 'NAN',
        'infinite' => '-INF',
    ])
        ->and(mb_check_encoding($context['invalid_utf8'], 'UTF-8'))->toBeTrue()
        ->and(json_encode($context))->not->toBeFalse();
});

it('records no context when it is empty', function (): void {
    $trace_id = $this->trace_context->start();

    Log::error('No context');

    expect(flushedLogEvents($trace_id)[0]['context'])->toBeNull();
});

it('replaces a context over 64 KB with a truncation marker', function (): void {
    $trace_id = $this->trace_context->start();

    Log::error('Big', ['payload' => str_repeat('a', 70000)]);

    expect(flushedLogEvents($trace_id)[0]['context'])->toBe(['_truncated' => true]);
});

it('truncates the message to 10 000 characters', function (): void {
    $trace_id = $this->trace_context->start();

    Log::error(str_repeat('é', 10050));

    expect(flushedLogEvents($trace_id)[0]['message'])->toBe(str_repeat('é', 10000));
});

it('does not recurse when logging happens while a message is recorded', function (): void {
    $trace_id = $this->trace_context->start();
    $value = new LoggingContextValue;

    Log::error('Outer', ['value' => $value]);

    $events = flushedLogEvents($trace_id);

    expect($value->calls)->toBe(1)
        ->and($events)->toHaveCount(1)
        ->and($events[0])->toMatchArray(['message' => 'Outer', 'context' => ['value' => 'logging value']]);
});

it('records nothing when logs are not sampled', function (): void {
    config(['app-watch.sample_rates.log' => 0]);
    $trace_id = $this->trace_context->start();

    Log::error('Not sampled');

    expect(flushedLogEvents($trace_id))->toBe([]);
});

it('records nothing inside an unsampled trace', function (): void {
    $trace_id = $this->trace_context->start(ignored: true);

    Log::error('Unsampled');

    expect(flushedLogEvents($trace_id))->toBe([]);
});

it('records nothing outside a trace', function (): void {
    Log::error('Outside');

    $trace_id = (string) $this->trace_context->start();

    expect(flushedLogEvents($trace_id))->toBe([]);
});

it('records nothing without a token', function (): void {
    $trace_id = $this->trace_context->start();
    config(['app-watch.token' => null]);

    Log::error('No token');

    expect($this->recorder->flush((string) $trace_id))->toBe([]);
});

it('is registered as a singleton', function (): void {
    expect(app(RecordLogListener::class))->toBe(app(RecordLogListener::class));
});
