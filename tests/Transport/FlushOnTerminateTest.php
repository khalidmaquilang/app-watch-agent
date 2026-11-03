<?php

declare(strict_types=1);

use AppWatch\Agent\Listeners\FlushTraceListener;
use AppWatch\Agent\Payloads\EventPayload;
use AppWatch\Agent\Recorder;
use AppWatch\Agent\Support\TraceContext;
use AppWatch\Agent\Tests\Fixtures\Jobs\TracedJob;
use AppWatch\Agent\Tests\Fixtures\Jobs\WorkerJob;
use AppWatch\Agent\Transport\DaemonTransport;
use AppWatch\Agent\Transport\NullTransport;
use AppWatch\Agent\Transport\Transport;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

beforeEach(function (): void {
    config(['app-watch.enabled' => true, 'app-watch.token' => 'environment-token', 'queue.default' => 'sync']);

    TracedJob::$trace_ids = [];
    TracedJob::$during = null;

    $this->trace_context = app(TraceContext::class);
    $this->trace_context->end();
    $this->recorder = app(Recorder::class);
    $this->transport = app(Transport::class);
    $this->request_trace_id = null;

    Route::get('/orders', function () {
        $this->request_trace_id = app(TraceContext::class)->traceId();

        Cache::get('orders:missing');
        Cache::put('orders:latest', 'value', 60);
        Log::warning('Orders are slow');

        return 'ok';
    });
});

/**
 * Use the real daemon transport, pointed at the given port.
 */
function useDaemonTransport(int $port): DaemonTransport
{
    config(['app-watch.daemon.host' => '127.0.0.1', 'app-watch.daemon.port' => $port]);
    app()->forgetInstance(Transport::class);
    app()->forgetInstance(DaemonTransport::class);

    return app(DaemonTransport::class);
}

/**
 * The types of the given events.
 *
 * @param  array<int, EventPayload>  $events
 * @return array<int, string>
 */
function eventTypes(array $events): array
{
    return array_map(fn (EventPayload $event): string => $event->type()->value, $events);
}

it('resolves the daemon transport while enabled and the null transport otherwise', function (array $config, string $expected): void {
    config($config);
    app()->forgetInstance(Transport::class);

    expect(app(Transport::class))->toBeInstanceOf($expected);
})->with([
    'enabled with a token' => [['app-watch.enabled' => true, 'app-watch.token' => 'environment-token'], DaemonTransport::class],
    'disabled' => [['app-watch.enabled' => false], NullTransport::class],
    'without a token' => [['app-watch.token' => null], NullTransport::class],
]);

it('sends every event of a request to the daemon as v1 JSON lines with the request trace id', function (): void {
    [$server, $port] = startTestDaemon();
    $transport = useDaemonTransport($port);

    $this->get('/orders')->assertOk();

    $connections = readTestDaemon($server);
    fclose($server);

    expect($this->request_trace_id)->not->toBeNull()
        ->and($connections)->toHaveCount(1)
        ->and(array_column($connections[0], 'type'))->toEqualCanonicalizing(['cache', 'cache', 'log', 'request'])
        ->and(array_unique(array_column($connections[0], 'trace_id')))->toBe([$this->request_trace_id])
        ->and($transport->droppedCount())->toBe(0)
        ->and($this->recorder->flush((string) $this->request_trace_id))->toBe([]);

    foreach ($connections[0] as $line) {
        expect(array_keys($line))->toBe(array_keys(fixtureEvent($line['type'])));
    }
});

it('still completes the request, without an exception or noticeable delay, when no daemon is listening', function (): void {
    $this->get('/orders')->assertOk();
    $started_at = hrtime(true);
    $this->get('/orders')->assertOk();
    $baseline_ms = (hrtime(true) - $started_at) / 1_000_000;

    $transport = useDaemonTransport(unusedPort());

    $started_at = hrtime(true);
    $this->get('/orders')->assertOk();
    $elapsed_ms = (hrtime(true) - $started_at) / 1_000_000;

    expect($elapsed_ms - $baseline_ms)->toBeLessThan(100.0)
        ->and($transport->droppedCount())->toBe(4)
        ->and($this->recorder->flush((string) $this->request_trace_id))->toBe([]);
});

it('sends the other events of a request whose log message has invalid UTF-8', function (): void {
    [$server, $port] = startTestDaemon();
    useDaemonTransport($port);
    Route::get('/broken-log', function () {
        Log::error("Card \xB1 declined", ['raw' => "\xC3\x28"]);
        Cache::get('orders:missing');

        return 'ok';
    });

    $this->get('/broken-log')->assertOk();

    $lines = readTestDaemon($server)[0];
    fclose($server);

    expect(array_column($lines, 'type'))->toEqualCanonicalizing(['log', 'cache', 'request'])
        ->and(collect($lines)->firstWhere('type', 'log')['message'])->toBe('Card ? declined');
});

it('flushes a request trace when the app terminates', function (): void {
    $this->get('/orders')->assertOk();

    expect(eventTypes($this->transport->events((string) $this->request_trace_id)))->toEqualCanonicalizing(['cache', 'cache', 'log', 'request'])
        ->and($this->transport->batches())->toHaveCount(1);
});

it('does not send anything for an unsampled request', function (): void {
    config(['app-watch.sample_rates.request' => 0]);

    $this->get('/orders')->assertOk();

    expect($this->transport->batches())->toBe([]);
});

it('flushes a sync job run as soon as it completes, separately from the request it ran in', function (): void {
    TracedJob::$during = fn (): mixed => Log::warning('Sending confirmation');
    Route::get('/checkout', function () {
        $this->request_trace_id = app(TraceContext::class)->traceId();
        TracedJob::dispatch();

        return 'ok';
    });

    $this->get('/checkout')->assertOk();

    $job_trace_id = (string) TracedJob::$trace_ids[0];
    $batches = $this->transport->batches();

    expect($batches)->toHaveCount(2)
        ->and(eventTypes($batches[0]))->toBe(['log', 'job'])
        ->and(array_unique(array_map(fn (EventPayload $event): string => $event->traceId(), $batches[0])))->toBe([$job_trace_id])
        ->and(eventTypes($batches[1]))->toBe(['request'])
        ->and($batches[1][0]->traceId())->toBe($this->request_trace_id);
});

it('flushes a failing sync job run', function (): void {
    expect(fn () => TracedJob::dispatch(should_throw: true))->toThrow(RuntimeException::class);

    $events = $this->transport->events((string) TracedJob::$trace_ids[0]);

    expect(eventTypes($events))->toBe(['job'])
        ->and($events[0]->toArray()['status'])->toBe('failed')
        ->and($this->recorder->flush((string) TracedJob::$trace_ids[0]))->toBe([]);
});

it('flushes a worker job run after it is processed, failed or released', function (string $event_class, string $status): void {
    $job = new WorkerJob(TracedJob::class, 'redis', 'emails');

    event(new JobProcessing('redis', $job));
    $trace_id = (string) $this->trace_context->traceId();
    Log::warning('Working');

    event(match ($event_class) {
        JobProcessed::class => new JobProcessed('redis', $job),
        JobFailed::class => new JobFailed('redis', $job, new RuntimeException('boom')),
        JobReleasedAfterException::class => new JobReleasedAfterException('redis', $job, new RuntimeException('boom'), 10),
    });

    $events = $this->transport->events($trace_id);

    expect(eventTypes($events))->toBe(['log', 'job'])
        ->and($events[1]->toArray()['status'])->toBe($status);
})->with([
    'processed' => [JobProcessed::class, 'processed'],
    'failed' => [JobFailed::class, 'failed'],
    'released' => [JobReleasedAfterException::class, 'released'],
]);

it('flushes events recorded after the job event before the run trace ends', function (): void {
    $job = new WorkerJob(TracedJob::class, 'redis', 'emails');

    event(new JobProcessing('redis', $job));
    $trace_id = (string) $this->trace_context->traceId();
    event(new JobFailed('redis', $job, new RuntimeException('boom')));
    Log::error('Cleaning up after the failed job');
    event(new JobAttempted('redis', $job, new RuntimeException('boom')));

    expect($this->transport->batches())->toHaveCount(2)
        ->and(eventTypes($this->transport->events($trace_id)))->toBe(['job', 'log'])
        ->and($this->trace_context->traceId())->toBeNull();
});

it('flushes a command trace when the command finishes', function (): void {
    event(new CommandStarting('orders:sync', new ArrayInput([]), new NullOutput));
    $trace_id = (string) $this->trace_context->traceId();
    Log::warning('Syncing orders');
    Cache::get('orders:missing');

    expect($this->transport->batches())->toBe([]);

    event(new CommandFinished('orders:sync', new ArrayInput([]), new NullOutput, 0));

    expect(eventTypes($this->transport->events($trace_id)))->toBe(['log', 'cache']);
});

it('sends nothing outside a trace or for an empty trace', function (): void {
    app(FlushTraceListener::class)->handle();

    $this->trace_context->start();
    app(FlushTraceListener::class)->handle();

    expect($this->transport->batches())->toBe([]);
});

it('leaves other traces buffered', function (): void {
    $this->recorder->record(queryEvent('other-trace'));
    $trace_id = (string) $this->trace_context->start();
    $this->recorder->record(queryEvent($trace_id));

    app(FlushTraceListener::class)->handle();

    expect($this->transport->events())->toHaveCount(1)
        ->and($this->recorder->flush('other-trace'))->toHaveCount(1);
});

it('never throws when the transport fails', function (): void {
    app()->instance(Transport::class, new class implements Transport
    {
        public function send(array $events): void
        {
            throw new RuntimeException('Transport exploded.');
        }
    });

    $this->get('/orders')->assertOk();
});
