<?php

declare(strict_types=1);

use AppWatch\Agent\Enums\JobStatusEnum;
use AppWatch\Agent\Payloads\JobEventPayload;
use AppWatch\Agent\Recorder;
use AppWatch\Agent\Support\TraceContext;
use AppWatch\Agent\Tests\Fixtures\Jobs\IgnoredJob;
use AppWatch\Agent\Tests\Fixtures\Jobs\TracedJob;
use AppWatch\Agent\Tests\Fixtures\Jobs\WorkerJob;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;

beforeEach(function (): void {
    config(['app-watch.enabled' => true, 'queue.default' => 'sync']);

    TracedJob::$trace_ids = [];
    TracedJob::$during = null;

    $this->recorder = app(Recorder::class);
    $this->trace_context = app(TraceContext::class);
    $this->trace_context->end();
});

it('records a processed sync job with its class, queue, connection and attempt', function (): void {
    TracedJob::dispatch();

    $trace_id = TracedJob::$trace_ids[0];
    $events = recordedEvents((string) $trace_id);

    expect($trace_id)->not->toBeNull()
        ->and($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(JobEventPayload::class)
        ->and($events[0]->toArray())->toMatchArray([
            'type' => 'job',
            'trace_id' => $trace_id,
            'job_class' => TracedJob::class,
            'queue' => 'sync',
            'connection' => 'sync',
            'status' => 'processed',
            'attempt' => 1,
        ])
        ->and($events[0]->duration_ms)->toBeGreaterThan(0.0);
});

it('records the queue and attempt of a job run by a worker', function (): void {
    $job = new WorkerJob(TracedJob::class, 'redis', 'emails', attempt: 3);

    event(new JobProcessing('redis', $job));
    $trace_id = $this->trace_context->traceId();
    event(new JobProcessed('redis', $job));
    event(new JobAttempted('redis', $job));

    expect(recordedEvents((string) $trace_id)[0]->toArray())->toMatchArray([
        'job_class' => TracedJob::class,
        'queue' => 'emails',
        'connection' => 'redis',
        'status' => 'processed',
        'attempt' => 3,
    ]);
});

it('records a failed job and still throws its exception', function (): void {
    expect(fn () => TracedJob::dispatch(should_throw: true))->toThrow(RuntimeException::class, 'Job failed on purpose.');

    $events = recordedEvents((string) TracedJob::$trace_ids[0]);

    expect($events)->toHaveCount(1)
        ->and($events[0]->toArray())->toMatchArray(['job_class' => TracedJob::class, 'status' => 'failed', 'attempt' => 1]);
});

it('records a job released after an exception once, under its own trace', function (): void {
    $job = new WorkerJob(TracedJob::class, 'redis', 'emails', attempt: 2);

    event(new JobProcessing('redis', $job));
    $trace_id = $this->trace_context->traceId();
    event(new JobReleasedAfterException('redis', $job, 10, new RuntimeException('Boom')));
    event(new JobFailed('redis', $job, new RuntimeException('Boom')));
    event(new JobAttempted('redis', $job, new RuntimeException('Boom')));

    $events = recordedEvents((string) $trace_id);

    expect($events)->toHaveCount(1)
        ->and($events[0]->toArray())->toMatchArray([
            'job_class' => TracedJob::class,
            'queue' => 'emails',
            'connection' => 'redis',
            'status' => JobStatusEnum::Released->value,
            'attempt' => 2,
        ])
        ->and($this->trace_context->traceId())->toBeNull();
});

it('ignores job end events without a matching start', function (): void {
    $job = new WorkerJob(TracedJob::class, 'redis', 'emails');

    event(new JobProcessed('redis', $job));
    event(new JobAttempted('redis', $job));

    expect($this->trace_context->traceId())->toBeNull();
});

it('records nothing for an ignored job class, including events inside its trace', function (string $pattern): void {
    config(['app-watch.ignore.jobs' => [$pattern]]);
    IgnoredJob::$during = function (?string $trace_id): void {
        app(Recorder::class)->record(queryEvent((string) $trace_id));
    };

    IgnoredJob::dispatch();

    expect(TracedJob::$trace_ids[0])->not->toBeNull()
        ->and(recordedEvents((string) TracedJob::$trace_ids[0]))->toBe([]);
})->with([
    'class name' => [IgnoredJob::class],
    'glob' => ['AppWatch\\Agent\\Tests\\Fixtures\\Jobs\\Ignored*'],
]);

it('records nothing when the job trace is not sampled', function (): void {
    config(['app-watch.sample_rates.job' => 0]);

    TracedJob::dispatch();

    expect(recordedEvents((string) TracedJob::$trace_ids[0]))->toBe([]);
});

it('records nothing without an environment id', function (): void {
    config(['app-watch.environment_id' => null]);

    TracedJob::dispatch();

    expect(TracedJob::$trace_ids)->toBe([null]);
});

it('gives every job run its own trace and ends it afterwards', function (): void {
    TracedJob::dispatch();
    TracedJob::dispatch();

    [$first_trace_id, $second_trace_id] = TracedJob::$trace_ids;
    $first_events = recordedEvents((string) $first_trace_id);
    $second_events = recordedEvents((string) $second_trace_id);

    expect($first_trace_id)->not->toBe($second_trace_id)
        ->and($first_events)->toHaveCount(1)
        ->and($first_events[0]->traceId())->toBe($first_trace_id)
        ->and($second_events)->toHaveCount(1)
        ->and($second_events[0]->traceId())->toBe($second_trace_id)
        ->and($this->trace_context->traceId())->toBeNull();
});

it('restores the trace a job was dispatched inside', function (): void {
    $parent_trace_id = $this->trace_context->start();

    TracedJob::dispatch();

    expect(TracedJob::$trace_ids[0])->not->toBe($parent_trace_id)
        ->and($this->trace_context->traceId())->toBe($parent_trace_id)
        ->and(recordedEvents((string) $parent_trace_id))->toBe([]);
});
