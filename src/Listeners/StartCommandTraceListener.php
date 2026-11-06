<?php

declare(strict_types=1);

namespace AppWatch\Agent\Listeners;

use AppWatch\Agent\Enums\TelemetryTypeEnum;
use AppWatch\Agent\Recorder;
use AppWatch\Agent\Support\IgnoreRules;
use AppWatch\Agent\Support\TraceContext;
use Illuminate\Console\Events\CommandStarting;
use Throwable;

/**
 * Starts a new trace for every artisan command except those matching `app-watch.ignore.commands`
 * (by default the long-running ones, whose work is traced per job or request, and the agent's own
 * `app-watch:*` upkeep). A command started while another command runs on the current trace (a
 * nested `Artisan::call()`), or inside a scheduled task run in this process (a `->call()` task
 * calling Artisan), keeps that trace, so its run is recorded on it. A command started by the
 * scheduler in a child process joins the task run's trace through TraceContext::start(), which
 * adopts the parent's TraceContext::PARENT_TRACE_ID_ENV.
 */
final readonly class StartCommandTraceListener
{
    /**
     * The agent services the listener uses.
     */
    public function __construct(
        private Recorder $recorder,
        private TraceContext $trace_context,
        private IgnoreRules $ignore_rules,
        private RecordCommandListener $record_command_listener,
        private RecordScheduledTaskListener $record_scheduled_task_listener,
    ) {}

    /**
     * Start the command's trace.
     */
    public function handle(CommandStarting $event): void
    {
        try {
            if (! $this->recorder->isEnabled() || $this->ignore_rules->shouldIgnoreCommand($event->command)) {
                return;
            }

            $current_trace_id = $this->trace_context->traceId();

            if ($this->record_command_listener->isRunningIn($current_trace_id)
                || $this->record_scheduled_task_listener->isRunningIn($current_trace_id)) {
                return;
            }

            $this->trace_context->start(TelemetryTypeEnum::Command);
        } catch (Throwable) {
            return;
        }
    }
}
