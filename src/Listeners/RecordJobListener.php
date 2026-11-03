<?php

declare(strict_types=1);

namespace AppWatch\Agent\Listeners;

use AppWatch\Agent\Enums\JobStatusEnum;
use AppWatch\Agent\Enums\TelemetryTypeEnum;
use AppWatch\Agent\Payloads\EventMetadata;
use AppWatch\Agent\Payloads\JobEventPayload;
use AppWatch\Agent\Recorder;
use AppWatch\Agent\Support\IgnoreRules;
use AppWatch\Agent\Support\TraceContext;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Throwable;

/**
 * Traces queued job runs: JobProcessing starts a nested trace for the run, the first of
 * JobProcessed / JobFailed / JobReleasedAfterException records the job event, and JobAttempted
 * (fired last, whatever the outcome) ends the trace and restores the one the job ran inside.
 * Bound as a singleton, because the runs in progress are kept between those events.
 */
final class RecordJobListener
{
    /**
     * The most job runs tracked at once; the oldest is forgotten beyond this.
     */
    public const int MAX_TRACKED_RUNS = 100;

    /**
     * The job runs in progress, by the job object's id.
     *
     * @var array<int, array{trace_id: string, started_at: int, recorded: bool}>
     */
    private array $runs = [];

    /**
     * The agent services the listener uses.
     */
    public function __construct(
        private readonly Recorder $recorder,
        private readonly TraceContext $trace_context,
        private readonly IgnoreRules $ignore_rules,
    ) {}

    /**
     * Start the run's trace; an ignored job class gets an unsampled trace, so nothing it does is recorded.
     */
    public function handleJobProcessing(JobProcessing $event): void
    {
        try {
            if (! $this->recorder->isEnabled()) {
                return;
            }

            $trace_id = $this->trace_context->startNested(
                TelemetryTypeEnum::Job,
                ignored: $this->ignore_rules->shouldIgnoreJob($event->job->resolveName()),
            );

            if ($trace_id === null) {
                return;
            }

            $this->runs[spl_object_id($event->job)] = [
                'trace_id' => $trace_id,
                'started_at' => (int) hrtime(true),
                'recorded' => false,
            ];

            if (count($this->runs) > self::MAX_TRACKED_RUNS) {
                array_shift($this->runs);
            }
        } catch (Throwable) {
            return;
        }
    }

    /**
     * Record a run that completed.
     */
    public function handleJobProcessed(JobProcessed $event): void
    {
        $this->record($event->connectionName, $event->job, JobStatusEnum::Processed);
    }

    /**
     * Record a run that failed for good.
     */
    public function handleJobFailed(JobFailed $event): void
    {
        $this->record($event->connectionName, $event->job, JobStatusEnum::Failed);
    }

    /**
     * Record a run that threw and was released back onto the queue.
     */
    public function handleJobReleasedAfterException(JobReleasedAfterException $event): void
    {
        $this->record($event->connectionName, $event->job, JobStatusEnum::Released);
    }

    /**
     * End the run's trace and restore the trace the job ran inside (or none, in a worker).
     */
    public function handleJobAttempted(JobAttempted $event): void
    {
        try {
            $run_key = spl_object_id($event->job);
            $run = $this->runs[$run_key] ?? null;

            if ($run === null) {
                return;
            }

            unset($this->runs[$run_key]);

            if ($this->trace_context->traceId() === $run['trace_id']) {
                $this->trace_context->endNested();
            }
        } catch (Throwable) {
            return;
        }
    }

    /**
     * Record the run's job event once, under the run's trace.
     */
    private function record(string $connection_name, Job $job, JobStatusEnum $status): void
    {
        try {
            $run_key = spl_object_id($job);
            $run = $this->runs[$run_key] ?? null;

            if ($run === null || $run['recorded']) {
                return;
            }

            $this->runs[$run_key]['recorded'] = true;
            $queue = $job->getQueue();

            $this->recorder->record(new JobEventPayload(
                metadata: EventMetadata::capture($run['trace_id']),
                job_class: $job->resolveName(),
                queue: is_string($queue) && $queue !== '' ? $queue : 'default',
                connection: $connection_name,
                status: $status,
                attempt: max(1, $job->attempts()),
                duration_ms: round(max(0.0, ((int) hrtime(true) - $run['started_at']) / 1_000_000), 3),
            ));
        } catch (Throwable) {
            return;
        }
    }
}
