<?php

declare(strict_types=1);

namespace AppWatch\Agent\Listeners;

use AppWatch\Agent\Recorder;
use AppWatch\Agent\Support\TraceContext;
use AppWatch\Agent\Transport\Transport;
use Throwable;

/**
 * Flushes the current trace's buffered events to the transport. Runs when the HTTP app
 * terminates, after a job run is recorded (JobProcessed / JobFailed / JobReleasedAfterException)
 * and again on JobAttempted for anything recorded after that, when a command finishes, and when
 * a scheduled task run is recorded (RecordScheduledTaskListener).
 */
final readonly class FlushTraceListener
{
    /**
     * The agent services the listener uses.
     */
    public function __construct(
        private Recorder $recorder,
        private TraceContext $trace_context,
        private Transport $transport,
    ) {}

    /**
     * Send the current trace's buffered events, if any.
     */
    public function handle(?object $event = null): void
    {
        try {
            $trace_id = $this->trace_context->traceId();

            if ($trace_id !== null) {
                $this->flush($trace_id);
            }
        } catch (Throwable) {
            return;
        }
    }

    /**
     * Send the given trace's buffered events, if any.
     */
    public function flush(string $trace_id): void
    {
        try {
            $events = $this->recorder->flush($trace_id);

            if ($events !== []) {
                $this->transport->send($events);
            }
        } catch (Throwable) {
            return;
        }
    }
}
