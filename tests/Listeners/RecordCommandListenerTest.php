<?php

declare(strict_types=1);

use AppWatch\Agent\Listeners\RecordCommandListener;
use AppWatch\Agent\Payloads\CommandEventPayload;
use AppWatch\Agent\Payloads\EventPayload;
use AppWatch\Agent\Payloads\ExceptionEventPayload;
use AppWatch\Agent\Recorder;
use AppWatch\Agent\Support\TraceContext;
use AppWatch\Agent\Tests\Fixtures\Commands\HorizonFixtureCommand;
use AppWatch\Agent\Tests\Fixtures\Commands\NestedFixtureCommand;
use AppWatch\Agent\Tests\Fixtures\Commands\ReportCommand;
use AppWatch\Agent\Transport\FakeTransport;
use AppWatch\Agent\Transport\Transport;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;

beforeEach(function (): void {
    config(['app-watch.enabled' => true, 'logging.default' => 'null']);

    // Laravel only re-routes Symfony's command events to CommandStarting / CommandFinished outside
    // unit tests, so turn it on and rebuild Artisan with the dispatcher.
    $kernel = app(Kernel::class);
    $kernel->rerouteSymfonyCommandEvents();
    $kernel->setArtisan(null);
    $kernel->registerCommand(new ReportCommand);
    $kernel->registerCommand(new NestedFixtureCommand);
    $kernel->registerCommand(new HorizonFixtureCommand);

    $this->trace_context = app(TraceContext::class);
    $this->trace_context->end();
    $this->recorder = app(Recorder::class);
    $this->recorder->reset();
});

/**
 * The command events recorded on the trace.
 *
 * @return array<int, CommandEventPayload>
 */
function recordedCommandEvents(?string $trace_id): array
{
    return array_values(array_filter(
        recordedEvents((string) $trace_id),
        static fn (EventPayload $event): bool => $event instanceof CommandEventPayload,
    ));
}

it('records one command event with redacted arguments on the command\'s trace', function (): void {
    Artisan::call('fixture:report', ['email' => 'jane@example.com', '--token' => 's3cr3t-token']);

    $trace_id = $this->trace_context->traceId();
    $events = recordedCommandEvents($trace_id);

    expect($trace_id)->not->toBeNull()
        ->and($events)->toHaveCount(1)
        ->and($events[0]->traceId())->toBe($trace_id)
        ->and($events[0]->command)->toBe('fixture:report')
        ->and($events[0]->exit_code)->toBe(0)
        ->and($events[0]->failed)->toBeFalse()
        ->and($events[0]->duration_ms)->toBeGreaterThan(0.0)
        ->and($events[0]->arguments)->toBe(['email' => '?', 'token' => '?']);

    $serialized = json_encode($events[0]->toArray(), JSON_THROW_ON_ERROR);

    expect($serialized)->not->toContain('jane@example.com')
        ->and($serialized)->not->toContain('s3cr3t-token')
        ->and($events[0]->toArray())->toMatchArray(['type' => 'command', 'trace_id' => $trace_id, 'failed' => false]);
});

it('flushes the command event with its trace when the command finishes', function (): void {
    Artisan::call('fixture:report', ['email' => 'jane@example.com']);

    $sent = app(Transport::class);

    expect($sent)->toBeInstanceOf(FakeTransport::class)
        ->and(array_filter($sent->events((string) $this->trace_context->traceId()), static fn (EventPayload $event): bool => $event instanceof CommandEventPayload))->toHaveCount(1);
});

it('keeps a set flag as true and leaves unset options out', function (): void {
    Artisan::call('fixture:report', ['email' => 'jane@example.com', '--dry-run' => true]);

    expect(recordedCommandEvents($this->trace_context->traceId())[0]->arguments)->toBe(['email' => '?', 'dry-run' => true]);
});

it('records a non-zero exit code as failed', function (): void {
    Artisan::call('fixture:report', ['email' => 'jane@example.com', '--exit' => 2]);

    $events = recordedCommandEvents($this->trace_context->traceId());

    expect($events)->toHaveCount(1)
        ->and($events[0]->exit_code)->toBe(2)
        ->and($events[0]->failed)->toBeTrue();
});

it('records a command that throws as failed, with its exception on the same trace', function (): void {
    // Testbench's console kernel rethrows instead of reporting, so report it as Laravel's kernel does.
    try {
        Artisan::call('fixture:report', ['email' => 'jane@example.com', '--throw' => true]);
    } catch (RuntimeException $exception) {
        app(ExceptionHandler::class)->report($exception);
    }

    $trace_id = $this->trace_context->traceId();
    $events = recordedEvents((string) $trace_id);
    $command_events = array_values(array_filter($events, static fn (EventPayload $event): bool => $event instanceof CommandEventPayload));
    $exception_events = array_values(array_filter($events, static fn (EventPayload $event): bool => $event instanceof ExceptionEventPayload));

    expect($exception ?? null)->toBeInstanceOf(RuntimeException::class)
        ->and($command_events)->toHaveCount(1)
        ->and($command_events[0]->failed)->toBeTrue()
        ->and($command_events[0]->exit_code)->toBe(1)
        ->and($exception_events)->toHaveCount(1)
        ->and($exception_events[0]->traceId())->toBe($trace_id)
        ->and($exception_events[0]->toArray()['message'])->toBe('Report failed on purpose.');
});

it('records a nested Artisan::call as its own command event on the outer trace', function (): void {
    Artisan::call('fixture:report', ['email' => 'jane@example.com', '--call' => 'fixture:nested']);

    $trace_id = $this->trace_context->traceId();
    $events = recordedCommandEvents($trace_id);

    expect(array_map(static fn (CommandEventPayload $event): string => $event->command, $events))->toBe(['fixture:nested', 'fixture:report'])
        ->and(array_map(static fn (CommandEventPayload $event): string => $event->traceId(), $events))->toBe([$trace_id, $trace_id])
        ->and($events[0]->arguments)->toBe([]);
});

it('starts a new trace for the next top-level command after a nested one', function (): void {
    Artisan::call('fixture:report', ['email' => 'jane@example.com', '--call' => 'fixture:nested']);
    $first_trace_id = $this->trace_context->traceId();

    Artisan::call('fixture:nested');

    expect($this->trace_context->traceId())->not->toBe($first_trace_id)
        ->and(recordedCommandEvents($this->trace_context->traceId()))->toHaveCount(1);
});

it('records `list` when artisan runs without a command', function (): void {
    app(Kernel::class)->handle(new ArrayInput([]), new BufferedOutput);

    $events = recordedCommandEvents($this->trace_context->traceId());

    expect($events)->toHaveCount(1)
        ->and($events[0]->command)->toBe('list');
});

it('gives an ignored command no trace and no record', function (): void {
    Artisan::call('horizon:fixture');

    expect($this->trace_context->traceId())->toBeNull()
        ->and(app(Transport::class)->events())->toBe([]);
});

it('records a default-ignored command once the ignore list is emptied', function (): void {
    config(['app-watch.ignore.commands' => []]);

    Artisan::call('horizon:fixture');

    $events = recordedCommandEvents($this->trace_context->traceId());

    expect($events)->toHaveCount(1)
        ->and($events[0]->command)->toBe('horizon:fixture');
});

it('records nothing while the agent is disabled', function (): void {
    config(['app-watch.enabled' => false]);

    Artisan::call('fixture:report', ['email' => 'jane@example.com']);

    expect($this->trace_context->traceId())->toBeNull()
        ->and(app(Transport::class)->events())->toBe([]);
});

it('never throws into the host app', function (): void {
    $listener = app(RecordCommandListener::class);
    $input = new ArrayInput([]);

    $this->trace_context->start();
    $listener->handleCommandStarting(new CommandStarting('fixture:report', $input, new NullOutput));

    // An input that throws while its arguments are read: the run is dropped, nothing propagates.
    $broken_input = new class([]) extends ArrayInput
    {
        public function getArguments(): array
        {
            throw new RuntimeException('Broken input.');
        }
    };
    $listener->handleCommandStarting(new CommandStarting('fixture:report', $broken_input, new NullOutput));

    $listener->handleCommandFinished(new CommandFinished('fixture:report', $broken_input, new NullOutput, 0));
    $listener->handleCommandFinished(new CommandFinished('fixture:report', $input, new NullOutput, 0));

    expect(recordedCommandEvents($this->trace_context->traceId()))->toHaveCount(1);
});

it('records nothing for a command it did not see start', function (): void {
    $this->trace_context->start();

    app(RecordCommandListener::class)->handleCommandFinished(new CommandFinished('fixture:report', new ArrayInput([]), new NullOutput, 0));

    expect(recordedCommandEvents($this->trace_context->traceId()))->toBe([]);
});
