<?php

declare(strict_types=1);

namespace AppWatch\Agent\Listeners;

use AppWatch\Agent\Recorder;
use AppWatch\Agent\Support\TraceContext;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Str;
use Throwable;

/**
 * Starts a new trace for every artisan command except the long-running ones (the agent daemon,
 * queue workers, the scheduler, Horizon and Octane), whose work is traced per job or request.
 */
final readonly class StartCommandTraceListener
{
    /**
     * Commands (globs) that never get a trace of their own.
     *
     * @var array<int, string>
     */
    public const array UNTRACED_COMMANDS = [
        'app-watch:agent',
        'queue:work',
        'queue:listen',
        'schedule:*',
        'horizon*',
        'octane:*',
    ];

    /**
     * The agent services the listener uses.
     */
    public function __construct(
        private Recorder $recorder,
        private TraceContext $trace_context,
    ) {}

    /**
     * Start the command's trace.
     */
    public function handle(CommandStarting $event): void
    {
        try {
            if (! $this->recorder->isEnabled() || Str::is(self::UNTRACED_COMMANDS, $event->command)) {
                return;
            }

            $this->trace_context->start();
        } catch (Throwable) {
            return;
        }
    }
}
