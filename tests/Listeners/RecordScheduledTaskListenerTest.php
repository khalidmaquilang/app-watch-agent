<?php

declare(strict_types=1);

use AppWatch\Agent\Enums\ScheduledTaskKindEnum;
use AppWatch\Agent\Enums\ScheduledTaskOutcomeEnum;
use AppWatch\Agent\Listeners\RecordScheduledTaskListener;
use AppWatch\Agent\Payloads\CommandEventPayload;
use AppWatch\Agent\Payloads\EventPayload;
use AppWatch\Agent\Payloads\ExceptionEventPayload;
use AppWatch\Agent\Payloads\ScheduledTaskEventPayload;
use AppWatch\Agent\Recorder;
use AppWatch\Agent\Support\TraceContext;
use AppWatch\Agent\Tests\Fixtures\Commands\ReportCommand;
use AppWatch\Agent\Tests\Fixtures\Jobs\TracedJob;
use AppWatch\Agent\Tests\Fixtures\Scheduling\InProcessSchedule;
use AppWatch\Agent\Transport\FakeTransport;
use AppWatch\Agent\Transport\Transport;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    config(['app-watch.enabled' => true, 'logging.default' => 'null', 'cache.default' => 'array', 'queue.default' => 'sync']);

    // Laravel only re-routes Symfony's command events to CommandStarting / CommandFinished outside
    // unit tests, so turn it on and rebuild Artisan with the dispatcher.
    $kernel = app(Kernel::class);
    $kernel->rerouteSymfonyCommandEvents();
    $kernel->setArtisan(null);
    $kernel->registerCommand(new ReportCommand);

    // A schedule of the test's own, without the agent's upkeep.
    $this->schedule = new InProcessSchedule;
    $this->app->instance(Schedule::class, $this->schedule);

    $this->trace_context = app(TraceContext::class);
    $this->trace_context->end();
    $this->recorder = app(Recorder::class);
    $this->recorder->reset();
    $this->transport = app(Transport::class);
    TracedJob::$trace_ids = [];
    TracedJob::$during = null;
});

afterEach(function (): void {
    TraceContext::clearParentTraceId();
});

/**
 * Every scheduled task event sent to the fake transport.
 *
 * @return array<int, ScheduledTaskEventPayload>
 */
function sentScheduledTaskEvents(): array
{
    $transport = app(Transport::class);

    return array_values(array_filter(
        $transport instanceof FakeTransport ? $transport->events() : [],
        static fn (EventPayload $event): bool => $event instanceof ScheduledTaskEventPayload,
    ));
}

it('records a scheduled command run through schedule:run, sharing its trace with the command run', function (): void {
    $this->schedule->inProcessCommand('fixture:report');

    Artisan::call('schedule:run');

    $events = sentScheduledTaskEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0]->task)->toBe('fixture:report')
        ->and($events[0]->kind)->toBe(ScheduledTaskKindEnum::Command)
        ->and($events[0]->expression)->toBe('* * * * *')
        ->and($events[0]->outcome)->toBe(ScheduledTaskOutcomeEnum::Succeeded)
        ->and($events[0]->exit_code)->toBe(0)
        ->and($events[0]->duration_ms)->toBeGreaterThan(0.0)
        ->and($events[0]->without_overlapping)->toBeFalse()
        ->and($events[0]->on_one_server)->toBeFalse();

    $command_events = array_values(array_filter(
        recordedEvents($events[0]->traceId()),
        static fn (EventPayload $event): bool => $event instanceof CommandEventPayload,
    ));

    expect($command_events)->toHaveCount(1)
        ->and($command_events[0]->command)->toBe('fixture:report')
        ->and(getenv(TraceContext::PARENT_TRACE_ID_ENV))->toBeFalse()
        ->and($this->trace_context->traceId())->toBeNull();
});

it('hands the run\'s trace id to the child process of an exec task', function (): void {
    $output_file = tempnam(sys_get_temp_dir(), 'app-watch-schedule-');
    $this->schedule->exec('printenv '.TraceContext::PARENT_TRACE_ID_ENV)->sendOutputTo($output_file);

    Artisan::call('schedule:run');

    $events = sentScheduledTaskEvents();
    $child_trace_id = trim((string) file_get_contents($output_file));
    unlink($output_file);

    expect($events)->toHaveCount(1)
        ->and($events[0]->kind)->toBe(ScheduledTaskKindEnum::Exec)
        ->and($events[0]->task)->toBe('printenv ?')
        ->and($events[0]->outcome)->toBe(ScheduledTaskOutcomeEnum::Succeeded)
        ->and($child_trace_id)->toBe($events[0]->traceId())
        ->and(TraceContext::isValidTraceId($child_trace_id))->toBeTrue()
        ->and(getenv(TraceContext::PARENT_TRACE_ID_ENV))->toBeFalse();
});

it('records a failing exec task as failed with its exit code, once', function (): void {
    $this->schedule->exec('exit 3');

    Artisan::call('schedule:run');

    $events = sentScheduledTaskEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0]->outcome)->toBe(ScheduledTaskOutcomeEnum::Failed)
        ->and($events[0]->exit_code)->toBe(3)
        ->and(getenv(TraceContext::PARENT_TRACE_ID_ENV))->toBeFalse();
});

it('records a throwing closure as a failed closure run, with its exception on the run\'s trace', function (): void {
    $this->schedule->call(static fn () => throw new RuntimeException('Nightly report failed.'));

    Artisan::call('schedule:run');

    $events = sentScheduledTaskEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0]->kind)->toBe(ScheduledTaskKindEnum::Closure)
        ->and($events[0]->task)->toBe('Closure')
        ->and($events[0]->outcome)->toBe(ScheduledTaskOutcomeEnum::Failed)
        ->and($events[0]->exit_code)->toBe(1);

    $exception_events = array_values(array_filter(
        recordedEvents($events[0]->traceId()),
        static fn (EventPayload $event): bool => $event instanceof ExceptionEventPayload,
    ));

    expect($exception_events)->toHaveCount(1)
        ->and($exception_events[0]->toArray()['message'])->toBe('Nightly report failed.')
        ->and(getenv(TraceContext::PARENT_TRACE_ID_ENV))->toBeFalse();
});

it('records a closure under its description and runs it on the run\'s trace', function (): void {
    $trace_ids = [];
    $this->schedule->call(function (TraceContext $trace_context) use (&$trace_ids): void {
        $trace_ids[] = $trace_context->traceId();
    })->description('Prune stale carts')->timezone('Asia/Manila');

    Artisan::call('schedule:run');

    $events = sentScheduledTaskEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0]->task)->toBe('Prune stale carts')
        ->and($events[0]->kind)->toBe(ScheduledTaskKindEnum::Closure)
        ->and($events[0]->timezone)->toBe('Asia/Manila')
        ->and($events[0]->outcome)->toBe(ScheduledTaskOutcomeEnum::Succeeded)
        ->and($trace_ids)->toBe([$events[0]->traceId()]);
});

it('records a command a closure task calls on the task\'s trace', function (): void {
    $this->schedule->call(static fn () => Artisan::call('fixture:report'))->description('Nightly report');

    Artisan::call('schedule:run');

    $events = sentScheduledTaskEvents();
    $command_events = array_values(array_filter(
        recordedEvents($events[0]->traceId()),
        static fn (EventPayload $event): bool => $event instanceof CommandEventPayload,
    ));

    expect($events)->toHaveCount(1)
        ->and($command_events)->toHaveCount(1)
        ->and($command_events[0]->command)->toBe('fixture:report');
});

it('records a scheduled job as a job run under its class', function (): void {
    $this->schedule->job(TracedJob::class);

    Artisan::call('schedule:run');

    $events = sentScheduledTaskEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0]->kind)->toBe(ScheduledTaskKindEnum::Job)
        ->and($events[0]->task)->toBe(TracedJob::class)
        ->and($events[0]->outcome)->toBe(ScheduledTaskOutcomeEnum::Succeeded)
        ->and(TracedJob::$trace_ids)->toHaveCount(1);
});

it('records a run skipped by a filter as skipped with no duration', function (): void {
    $this->schedule->call(static fn () => null)->when(static fn (): bool => false);

    Artisan::call('schedule:run');

    $events = sentScheduledTaskEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0]->outcome)->toBe(ScheduledTaskOutcomeEnum::Skipped)
        ->and($events[0]->duration_ms)->toBe(0.0)
        ->and($events[0]->exit_code)->toBeNull()
        ->and($events[0]->without_overlapping)->toBeFalse()
        ->and($this->trace_context->traceId())->toBeNull();
});

it('records a run skipped because its overlap mutex is held as skipped', function (): void {
    $ran = false;
    $event = $this->schedule->call(function () use (&$ran): void {
        $ran = true;
    })->name('Sync stock')->withoutOverlapping();
    $event->mutex->create($event);

    Artisan::call('schedule:run');

    $events = sentScheduledTaskEvents();

    expect($ran)->toBeFalse()
        ->and($events)->toHaveCount(1)
        ->and($events[0]->task)->toBe('Sync stock')
        ->and($events[0]->outcome)->toBe(ScheduledTaskOutcomeEnum::Skipped)
        ->and($events[0]->duration_ms)->toBe(0.0)
        ->and($events[0]->exit_code)->toBeNull()
        ->and($events[0]->without_overlapping)->toBeTrue();
});

it('records two runs of the same command with different expressions as distinct tasks', function (): void {
    $this->schedule->inProcessCommand('fixture:report')->everyMinute();
    $this->schedule->inProcessCommand('fixture:report')->cron('* * * * *')->onOneServer();

    Artisan::call('schedule:run');

    $events = sentScheduledTaskEvents();

    expect($events)->toHaveCount(2)
        ->and($events[0]->traceId())->not->toBe($events[1]->traceId())
        ->and(array_map(static fn (ScheduledTaskEventPayload $event): bool => $event->on_one_server, $events))->toBe([false, true]);
});

it('redacts the argument values of a command task and keeps its option names', function (): void {
    $event = $this->schedule->command('fixture:report jane@example.com --token=s3cr3t --dry-run');

    event(new ScheduledTaskStarting($event));
    event(new ScheduledTaskSkipped($event));
    $event->exitCode = 0;
    event(new ScheduledTaskFinished($event, 0.5));

    $events = sentScheduledTaskEvents();

    expect($events)->toHaveCount(2)
        ->and($events[0]->task)->toBe('fixture:report ? --token=? --dry-run')
        ->and(json_encode(array_map(static fn (ScheduledTaskEventPayload $event): array => $event->toArray(), $events)))
        ->not->toContain('jane@example.com')
        ->not->toContain('s3cr3t');
});

it('hands the trace id to a command task\'s child process only while it runs', function (): void {
    $event = $this->schedule->command('orders:sync');

    event(new ScheduledTaskStarting($event));
    $trace_id = $this->trace_context->traceId();

    expect($trace_id)->not->toBeNull()
        ->and(getenv(TraceContext::PARENT_TRACE_ID_ENV))->toBe($trace_id)
        ->and($_SERVER[TraceContext::PARENT_TRACE_ID_ENV] ?? null)->toBe($trace_id);

    $event->exitCode = 0;
    event(new ScheduledTaskFinished($event, 0.25));

    expect(getenv(TraceContext::PARENT_TRACE_ID_ENV))->toBeFalse()
        ->and($_SERVER)->not->toHaveKey(TraceContext::PARENT_TRACE_ID_ENV)
        ->and(sentScheduledTaskEvents()[0]->traceId())->toBe($trace_id);
});

it('never hands a trace id to the child processes of a callback task', function (): void {
    $event = $this->schedule->call(static fn () => null);

    event(new ScheduledTaskStarting($event));

    expect($this->trace_context->traceId())->not->toBeNull()
        ->and(getenv(TraceContext::PARENT_TRACE_ID_ENV))->toBeFalse();
});

it('records a foreground command that exits non-zero once, as failed, when Finished is followed by Failed', function (): void {
    $event = $this->schedule->command('orders:sync');

    event(new ScheduledTaskStarting($event));
    $event->exitCode = 2;
    event(new ScheduledTaskFinished($event, 0.25));

    expect(sentScheduledTaskEvents())->toBe([]);

    event(new ScheduledTaskFailed($event, new RuntimeException('Scheduled command failed.'), 0.25));

    $events = sentScheduledTaskEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0]->outcome)->toBe(ScheduledTaskOutcomeEnum::Failed)
        ->and($events[0]->exit_code)->toBe(2)
        ->and(getenv(TraceContext::PARENT_TRACE_ID_ENV))->toBeFalse();
});

it('records a background run as succeeded without an exit code', function (): void {
    $event = $this->schedule->command('orders:sync')->runInBackground();

    event(new ScheduledTaskStarting($event));
    event(new ScheduledTaskFinished($event, 0.0));

    $events = sentScheduledTaskEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0]->outcome)->toBe(ScheduledTaskOutcomeEnum::Succeeded)
        ->and($events[0]->exit_code)->toBeNull();
});

it('restores the trace that was current before the run', function (): void {
    $outer_trace_id = $this->trace_context->start();
    $event = $this->schedule->call(static fn () => null);

    event(new ScheduledTaskStarting($event));

    expect($this->trace_context->traceId())->not->toBe($outer_trace_id);

    $event->exitCode = 0;
    event(new ScheduledTaskFinished($event, 0.0));

    expect($this->trace_context->traceId())->toBe($outer_trace_id);
});

it('ends a failed run\'s trace at the next task event', function (): void {
    $failed_event = $this->schedule->call(static fn () => null);
    $next_event = $this->schedule->call(static fn () => null)->when(static fn (): bool => false);

    event(new ScheduledTaskStarting($failed_event));
    $failed_trace_id = $this->trace_context->traceId();
    event(new ScheduledTaskFailed($failed_event, new RuntimeException('boom'), 0.0));

    expect($this->trace_context->traceId())->toBe($failed_trace_id);

    event(new ScheduledTaskSkipped($next_event));

    expect($this->trace_context->traceId())->toBeNull()
        ->and(sentScheduledTaskEvents())->toHaveCount(2);
});

it('does not record the agent\'s own app-watch:* tasks by default', function (): void {
    $event = $this->schedule->command('app-watch:rollups minute');

    event(new ScheduledTaskStarting($event));

    expect($this->trace_context->traceId())->toBeNull()
        ->and(getenv(TraceContext::PARENT_TRACE_ID_ENV))->toBeFalse();

    $event->exitCode = 0;
    event(new ScheduledTaskFinished($event, 0.1));
    event(new ScheduledTaskSkipped($event));

    expect(sentScheduledTaskEvents())->toBe([]);
});

it('records the agent\'s own tasks once ignore.scheduled_tasks is cleared', function (): void {
    config(['app-watch.ignore.scheduled_tasks' => []]);
    $event = $this->schedule->command('app-watch:rollups minute');

    event(new ScheduledTaskStarting($event));
    $event->exitCode = 0;
    event(new ScheduledTaskFinished($event, 0.1));

    $events = sentScheduledTaskEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0]->task)->toBe('app-watch:rollups ?');
});

it('stays silent while the agent is disabled', function (): void {
    config(['app-watch.enabled' => false]);
    $this->schedule->inProcessCommand('fixture:report');
    $this->schedule->call(static fn () => null)->when(static fn (): bool => false);

    Artisan::call('schedule:run');

    expect(sentScheduledTaskEvents())->toBe([])
        ->and(getenv(TraceContext::PARENT_TRACE_ID_ENV))->toBeFalse()
        ->and($this->transport->events())->toBe([]);
});

it('ignores a finish or failure without a tracked start', function (): void {
    $event = $this->schedule->command('orders:sync');
    $event->exitCode = 0;

    event(new ScheduledTaskFinished($event, 0.1));
    event(new ScheduledTaskFailed($event, new RuntimeException('boom')));

    expect(sentScheduledTaskEvents())->toBe([]);
});

it('is bound as a singleton', function (): void {
    expect(app(RecordScheduledTaskListener::class))->toBe(app(RecordScheduledTaskListener::class));
});
