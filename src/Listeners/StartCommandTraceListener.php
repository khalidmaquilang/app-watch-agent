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
 * nested `Artisan::call()`) keeps that trace, so its run is recorded on it.
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

            if ($this->record_command_listener->isRunningIn($this->trace_context->traceId())) {
                return;
            }

            $this->trace_context->start(TelemetryTypeEnum::Command);
        } catch (Throwable) {
            return;
        }
    }
}
