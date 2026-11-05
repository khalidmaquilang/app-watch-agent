<?php

declare(strict_types=1);

use AppWatch\Agent\Listeners\RecordExceptionListener;
use AppWatch\Agent\Payloads\EventPayload;
use AppWatch\Agent\Payloads\ExceptionEventPayload;
use AppWatch\Agent\Recorder;
use AppWatch\Agent\Support\TraceContext;
use AppWatch\Agent\Tests\Fixtures\Exceptions\IgnoredException;
use AppWatch\Agent\Tests\Fixtures\Exceptions\WrapExceptionHandlerServiceProvider;
use AppWatch\Agent\Tests\Fixtures\Exceptions\WrappingExceptionHandler;
use Illuminate\Contracts\Debug\ExceptionHandler;

beforeEach(function (): void {
    config([
        'app-watch.enabled' => true,
        'logging.default' => 'null',
    ]);

    $this->trace_context = app(TraceContext::class);
    $this->trace_context->end();

    $this->recorder = app(Recorder::class);
    $this->recorder->reset();
});

/**
 * Flush the trace and return its exception events as arrays.
 *
 * @return array<int, array<string, mixed>>
 */
function flushedExceptionEvents(?string $trace_id): array
{
    $events = array_filter(
        app(Recorder::class)->flush((string) $trace_id),
        fn (EventPayload $event): bool => $event instanceof ExceptionEventPayload,
    );

    return array_values(array_map(fn (EventPayload $event): array => $event->toArray(), $events));
}

/**
 * Throw an exception from the given depth of nested calls.
 */
function throwFromDepth(int $depth, mixed $argument): never
{
    if ($depth > 0) {
        throwFromDepth($depth - 1, $argument);
    }

    throw new RuntimeException('deep');
}

it('records an exception reported with report() as handled', function (): void {
    $trace_id = $this->trace_context->start();
    $exception = new RuntimeException('boom');

    report($exception);

    $events = flushedExceptionEvents($trace_id);

    expect($events)->toHaveCount(1)
        ->and($events[0])->toMatchArray([
            'type' => 'exception',
            'trace_id' => $trace_id,
            'class' => RuntimeException::class,
            'message' => 'boom',
            'file' => __FILE__,
            'line' => $exception->getLine(),
            'handled' => true,
        ])
        ->and($events[0]['trace'])->not->toBeEmpty()
        ->and(count($events[0]['trace']))->toBeLessThanOrEqual(100)
        ->and(array_keys($events[0]['trace'][0]))->toBe(['file', 'line', 'function', 'class']);
});

it('records at most 100 frames and never their arguments', function (): void {
    $trace_id = $this->trace_context->start();

    try {
        throwFromDepth(150, 'argument-secret');
    } catch (RuntimeException $exception) {
        report($exception);
    }

    $trace = flushedExceptionEvents($trace_id)[0]['trace'];

    expect($trace)->toHaveCount(100)
        ->and($trace[0])->toMatchArray(['function' => 'throwFromDepth', 'class' => null])
        ->and(json_encode($trace, JSON_THROW_ON_ERROR))->not->toContain('argument-secret')->not->toContain('"args"');
});

it('records an exception reported by the framework as unhandled', function (): void {
    $trace_id = $this->trace_context->start();

    app(ExceptionHandler::class)->report(new LogicException('top level'));

    expect(flushedExceptionEvents($trace_id)[0])->toMatchArray([
        'class' => LogicException::class,
        'message' => 'top level',
        'handled' => false,
    ]);
});

it('records an exception reported through rescue() as handled', function (): void {
    $trace_id = $this->trace_context->start();

    rescue(fn () => throw new RuntimeException('rescued'));

    expect(flushedExceptionEvents($trace_id)[0])->toMatchArray(['message' => 'rescued', 'handled' => true]);
});

it('records nothing for an exception in dontReport', function (): void {
    app(ExceptionHandler::class)->dontReport(IgnoredException::class);
    $trace_id = $this->trace_context->start();

    report(new IgnoredException('ignored'));

    expect(flushedExceptionEvents($trace_id))->toBe([]);
});

it('records exceptions even inside an unsampled trace', function (): void {
    config(['app-watch.sample_rates.exception' => 0]);
    $trace_id = $this->trace_context->start(ignored: true);

    report(new RuntimeException('always'));

    expect(flushedExceptionEvents($trace_id))->toHaveCount(1);
});

it('records nothing outside a trace', function (): void {
    report(new RuntimeException('outside'));

    $trace_id = (string) $this->trace_context->start();

    expect(flushedExceptionEvents($trace_id))->toBe([]);
});

it('records nothing without an environment id', function (): void {
    $trace_id = $this->trace_context->start();
    config(['app-watch.environment_id' => null]);

    report(new RuntimeException('no token'));

    expect($this->recorder->flush((string) $trace_id))->toBe([]);
});

it('still lets the handler log the reported exception', function (): void {
    $trace_id = $this->trace_context->start();

    report(new RuntimeException('logged too'));

    $types = array_map(fn (EventPayload $event): string => $event->type()->value, $this->recorder->flush((string) $trace_id));

    expect($types)->toBe(['exception', 'log']);
});

it('records an exception once when another provider wraps the exception handler', function (): void {
    $this->extra_providers = [WrapExceptionHandlerServiceProvider::class];
    $this->refreshApplication();
    config(['logging.default' => 'null']);

    $trace_id = app(TraceContext::class)->start();

    report(new RuntimeException('wrapped'));

    $events = flushedExceptionEvents($trace_id);

    expect(app(ExceptionHandler::class))->toBeInstanceOf(WrappingExceptionHandler::class)
        ->and($events)->toHaveCount(1)
        ->and($events[0]['message'])->toBe('wrapped');
});

it('is registered as a singleton', function (): void {
    expect(app(RecordExceptionListener::class))->toBe(app(RecordExceptionListener::class));
});
