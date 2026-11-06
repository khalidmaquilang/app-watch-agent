<?php

declare(strict_types=1);

namespace AppWatch\Agent\Listeners;

use AppWatch\Agent\Enums\ScheduledTaskKindEnum;
use AppWatch\Agent\Enums\ScheduledTaskOutcomeEnum;
use AppWatch\Agent\Enums\TelemetryTypeEnum;
use AppWatch\Agent\Payloads\EventMetadata;
use AppWatch\Agent\Payloads\ScheduledTaskEventPayload;
use AppWatch\Agent\Recorder;
use AppWatch\Agent\Support\IgnoreRules;
use AppWatch\Agent\Support\TraceContext;
use Closure;
use DateTimeZone;
use Illuminate\Console\Application;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use ReflectionFunction;
use ReflectionProperty;
use Throwable;

use function Illuminate\Support\enum_value;

/**
 * Records scheduled task runs (`schedule:run` / `schedule:work`). ScheduledTaskStarting starts a
 * nested trace for the run, so whatever the task does in this process (a `->call()` or `->job()`
 * callback) is recorded on it; for a `->command()` or `->exec()` task, which runs in a child
 * process, the trace id is also handed to that process (TraceContext::PARENT_TRACE_ID_ENV), so the
 * command run it records joins the same trace. ScheduledTaskFinished / ScheduledTaskFailed record
 * the run and flush its trace; ScheduledTaskSkipped (filters, maintenance pause, onOneServer)
 * records a skipped run on a trace of its own. Bound as a singleton, because the runs in progress
 * are kept between those events. Never throws into the host app.
 */
final class RecordScheduledTaskListener
{
    /**
     * The most task runs tracked at once; the oldest is forgotten beyond this.
     */
    public const int MAX_TRACKED_RUNS = 100;

    /**
     * The longest recorded `task`, in characters.
     */
    public const int MAX_TASK_LENGTH = 1024;

    /**
     * What every argument value of a recorded command or shell command is replaced by.
     */
    public const string REDACTED = '?';

    /**
     * What a closure task without a description is recorded as.
     */
    public const string CLOSURE_TASK = 'Closure';

    /**
     * The runs in progress, by the task (Event) object's id.
     *
     * @var array<int, array{trace_id: string, started_at: int, kind: ScheduledTaskKindEnum, task: string}>
     */
    private array $runs = [];

    /**
     * The trace of a failed run that is kept current until the next task event, so the exception the
     * scheduler reports right after ScheduledTaskFailed is recorded on it. Null when there is none.
     */
    private ?string $closing_trace_id = null;

    /**
     * The agent services the listener uses.
     */
    public function __construct(
        private readonly Recorder $recorder,
        private readonly TraceContext $trace_context,
        private readonly IgnoreRules $ignore_rules,
        private readonly FlushTraceListener $flush_trace_listener,
    ) {}

    /**
     * Whether a task run is in progress on the given trace.
     */
    public function isRunningIn(?string $trace_id): bool
    {
        if ($trace_id === null) {
            return false;
        }

        foreach ($this->runs as $run) {
            if ($run['trace_id'] === $trace_id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Start the run's trace and hand its id to the child process of a command or exec task.
     * Ignored tasks get no trace.
     */
    public function handleStarting(ScheduledTaskStarting $event): void
    {
        try {
            $this->closePendingTrace();

            if (! $this->recorder->isEnabled()) {
                return;
            }

            [$kind, $task] = $this->describe($event->task);

            if ($this->ignore_rules->shouldIgnoreScheduledTask($task)) {
                return;
            }

            $trace_id = $this->trace_context->startNested(TelemetryTypeEnum::ScheduledTask);

            if ($trace_id === null) {
                return;
            }

            $this->runs[spl_object_id($event->task)] = [
                'trace_id' => $trace_id,
                'started_at' => (int) hrtime(true),
                'kind' => $kind,
                'task' => $task,
            ];

            if (count($this->runs) > self::MAX_TRACKED_RUNS) {
                array_shift($this->runs);
            }

            if (in_array($kind, [ScheduledTaskKindEnum::Command, ScheduledTaskKindEnum::Exec], true)) {
                TraceContext::exportParentTraceId($trace_id);
            }
        } catch (Throwable) {
            TraceContext::clearParentTraceId();

            return;
        }
    }

    /**
     * Record the finished run: skipped when the overlap mutex was held, failed on a non-zero exit
     * code, succeeded otherwise. A foreground run with a non-zero exit code is left to
     * ScheduledTaskFailed, which the scheduler fires right after this.
     */
    public function handleFinished(ScheduledTaskFinished $event): void
    {
        try {
            $task = $event->task;
            $run_key = spl_object_id($task);
            $run = $this->runs[$run_key] ?? null;

            if ($run === null) {
                return;
            }

            $skipped = $task->skippedBecauseOverlapping === true;
            $exit_code = $this->exitCode($task);
            $failed = ! $skipped && $exit_code !== null && $exit_code !== 0;

            if ($failed && ! $task->runInBackground) {
                return;
            }

            unset($this->runs[$run_key]);

            $this->recordRun(
                run: $run,
                task: $task,
                outcome: match (true) {
                    $skipped => ScheduledTaskOutcomeEnum::Skipped,
                    $failed => ScheduledTaskOutcomeEnum::Failed,
                    default => ScheduledTaskOutcomeEnum::Succeeded,
                },
                duration_ms: $skipped ? 0.0 : $this->elapsedMs($run['started_at']),
                exit_code: $skipped ? null : $exit_code,
                keep_trace_open: false,
            );
        } catch (Throwable) {
            return;
        } finally {
            TraceContext::clearParentTraceId();
        }
    }

    /**
     * Record a run the scheduler skipped before starting it (filters, maintenance pause, or
     * onOneServer when another server already ran it), on a trace of its own.
     */
    public function handleSkipped(ScheduledTaskSkipped $event): void
    {
        try {
            $this->closePendingTrace();

            if (! $this->recorder->isEnabled()) {
                return;
            }

            [$kind, $task] = $this->describe($event->task);

            if ($this->ignore_rules->shouldIgnoreScheduledTask($task)) {
                return;
            }

            $trace_id = $this->trace_context->startNested(TelemetryTypeEnum::ScheduledTask);

            if ($trace_id === null) {
                return;
            }

            $this->recordRun(
                run: ['trace_id' => $trace_id, 'started_at' => (int) hrtime(true), 'kind' => $kind, 'task' => $task],
                task: $event->task,
                outcome: ScheduledTaskOutcomeEnum::Skipped,
                duration_ms: 0.0,
                exit_code: null,
                keep_trace_open: false,
            );
        } catch (Throwable) {
            return;
        } finally {
            TraceContext::clearParentTraceId();
        }
    }

    /**
     * Record a failed run (a callback that threw, or a command that exited non-zero). Its trace
     * stays current until the next task event, so the exception the scheduler reports next lands on it.
     */
    public function handleFailed(ScheduledTaskFailed $event): void
    {
        try {
            $run_key = spl_object_id($event->task);
            $run = $this->runs[$run_key] ?? null;

            if ($run === null) {
                return;
            }

            unset($this->runs[$run_key]);

            $this->recordRun(
                run: $run,
                task: $event->task,
                outcome: ScheduledTaskOutcomeEnum::Failed,
                duration_ms: $this->elapsedMs($run['started_at']),
                exit_code: $this->exitCode($event->task),
                keep_trace_open: true,
            );
        } catch (Throwable) {
            return;
        } finally {
            TraceContext::clearParentTraceId();
        }
    }

    /**
     * Record the run's event on its trace and flush the trace, then end the trace (restoring the
     * one it suspended), or keep it current until the next task event.
     *
     * @param  array{trace_id: string, started_at: int, kind: ScheduledTaskKindEnum, task: string}  $run
     */
    private function recordRun(
        array $run,
        Event $task,
        ScheduledTaskOutcomeEnum $outcome,
        float $duration_ms,
        ?int $exit_code,
        bool $keep_trace_open,
    ): void {
        try {
            $this->recorder->record(new ScheduledTaskEventPayload(
                metadata: EventMetadata::capture($run['trace_id']),
                task: $run['task'],
                kind: $run['kind'],
                expression: $task->expression,
                timezone: $this->timezone($task),
                outcome: $outcome,
                duration_ms: $duration_ms,
                exit_code: $exit_code,
                without_overlapping: $task->withoutOverlapping === true,
                on_one_server: $task->onOneServer === true,
            ));
        } finally {
            $this->flush_trace_listener->flush($run['trace_id']);

            if ($this->trace_context->traceId() === $run['trace_id']) {
                if ($keep_trace_open) {
                    $this->closing_trace_id = $run['trace_id'];
                } else {
                    $this->trace_context->endNested();
                }
            }
        }
    }

    /**
     * Flush and end a failed run's trace that was kept current, if it still is.
     */
    private function closePendingTrace(): void
    {
        $trace_id = $this->closing_trace_id;
        $this->closing_trace_id = null;

        if ($trace_id === null || $this->trace_context->traceId() !== $trace_id) {
            return;
        }

        $this->flush_trace_listener->flush($trace_id);
        $this->trace_context->endNested();
    }

    /**
     * The task's kind and how it is recorded: the Artisan command (without the `php artisan`
     * prefix) or shell command with every argument value redacted, the job class, or the closure's
     * description (else its callable string, else "Closure").
     *
     * @return array{0: ScheduledTaskKindEnum, 1: string}
     */
    private function describe(Event $task): array
    {
        if ($task instanceof CallbackEvent) {
            $callback = (new ReflectionProperty(CallbackEvent::class, 'callback'))->getValue($task);
            $description = is_string($task->description) && $task->description !== '' ? $task->description : null;

            if ($callback instanceof Closure && $this->isScheduledJob($callback)) {
                $job = (new ReflectionFunction($callback))->getClosureUsedVariables()['job'] ?? null;
                $job_class = is_object($job) ? $job::class : (is_string($job) ? $job : self::CLOSURE_TASK);

                return [ScheduledTaskKindEnum::Job, $this->cut($description ?? $job_class)];
            }

            return [
                ScheduledTaskKindEnum::Closure,
                $this->cut($description ?? (is_string($callback) && $callback !== '' ? $callback : self::CLOSURE_TASK)),
            ];
        }

        $command = trim((string) $task->command);
        $artisan_prefix = Application::phpBinary().' '.Application::artisanBinary().' ';

        if (str_starts_with($command, $artisan_prefix)) {
            return [ScheduledTaskKindEnum::Command, $this->cut($this->redactCommand(substr($command, strlen($artisan_prefix))))];
        }

        return [ScheduledTaskKindEnum::Exec, $this->cut($this->redactCommand($command))];
    }

    /**
     * Whether the callback is the closure Schedule::job() wraps a job in.
     */
    private function isScheduledJob(Closure $callback): bool
    {
        $function = new ReflectionFunction($callback);
        $scope = $function->getClosureScopeClass();

        return $scope !== null
            && ($scope->getName() === Schedule::class || $scope->isSubclassOf(Schedule::class))
            && array_key_exists('job', $function->getClosureUsedVariables());
    }

    /**
     * The command with its first word (the command name or program) kept, option names kept
     * (`--force`, `--to=?`) and every other word replaced by REDACTED, so values passed on the
     * command line are never recorded.
     */
    private function redactCommand(string $command): string
    {
        preg_match_all('/(?:\'[^\']*\'|"[^"]*"|[^\s\'"])+/', $command, $matches);
        $words = $matches[0];

        if ($words === []) {
            return $command;
        }

        $redacted = [array_shift($words)];

        foreach ($words as $word) {
            $redacted[] = match (true) {
                str_starts_with($word, '-') && str_contains($word, '=') => substr($word, 0, (int) strpos($word, '=') + 1).self::REDACTED,
                str_starts_with($word, '-') => $word,
                default => self::REDACTED,
            };
        }

        return implode(' ', $redacted);
    }

    /**
     * The task's timezone name, null when it has none.
     */
    private function timezone(Event $task): ?string
    {
        $timezone = enum_value($task->timezone);

        return match (true) {
            $timezone instanceof DateTimeZone => $timezone->getName(),
            is_string($timezone) && $timezone !== '' => $timezone,
            default => null,
        };
    }

    /**
     * The task's exit code, null when it did not report one.
     */
    private function exitCode(Event $task): ?int
    {
        return is_int($task->exitCode) ? $task->exitCode : null;
    }

    /**
     * Milliseconds since the given hrtime.
     */
    private function elapsedMs(int $started_at): float
    {
        return round(max(0.0, ((int) hrtime(true) - $started_at) / 1_000_000), 3);
    }

    /**
     * The text cut to MAX_TASK_LENGTH characters.
     */
    private function cut(string $text): string
    {
        return mb_substr(mb_scrub($text, 'UTF-8'), 0, self::MAX_TASK_LENGTH, 'UTF-8');
    }
}
