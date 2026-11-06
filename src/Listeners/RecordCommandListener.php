<?php

declare(strict_types=1);

namespace AppWatch\Agent\Listeners;

use AppWatch\Agent\Payloads\CommandEventPayload;
use AppWatch\Agent\Payloads\EventMetadata;
use AppWatch\Agent\Recorder;
use AppWatch\Agent\Support\IgnoreRules;
use AppWatch\Agent\Support\TraceContext;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Symfony\Component\Console\Input\InputInterface;
use Throwable;

/**
 * Records Artisan command runs: CommandStarting (after StartCommandTraceListener) remembers the
 * run and its trace, and CommandFinished records it with its redacted arguments, exit code and
 * duration, before FlushTraceListener sends the trace. Runs are kept as a stack, so a nested
 * `Artisan::call()` is recorded as its own command event on the outer command's trace. Bound as a
 * singleton, because the runs in progress are kept between those events.
 */
final class RecordCommandListener
{
    /**
     * The most command runs tracked at once; the oldest is forgotten beyond this.
     */
    public const int MAX_TRACKED_RUNS = 100;

    /**
     * The most argument and option keys recorded per run.
     */
    public const int MAX_ARGUMENTS = 100;

    /**
     * What every recorded non-boolean argument or option value is replaced by.
     */
    public const string REDACTED = '?';

    /**
     * The runs in progress, outermost first.
     *
     * @var array<int, array{input_id: int, trace_id: string, started_at: int}>
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
     * Whether a command run is in progress on the given trace.
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
     * Remember the run under the current trace; ignored commands and commands outside a trace are not tracked.
     */
    public function handleCommandStarting(CommandStarting $event): void
    {
        try {
            $trace_id = $this->trace_context->traceId();

            if ($trace_id === null || ! $this->recorder->isEnabled() || $this->ignore_rules->shouldIgnoreCommand($event->command)) {
                return;
            }

            $this->runs[] = [
                'input_id' => spl_object_id($event->input),
                'trace_id' => $trace_id,
                'started_at' => (int) hrtime(true),
            ];

            if (count($this->runs) > self::MAX_TRACKED_RUNS) {
                array_shift($this->runs);
            }
        } catch (Throwable) {
            return;
        }
    }

    /**
     * Record the finished run under the trace it started in. Runs started after it that never
     * finished (an inner command that threw past its CommandFinished) are forgotten with it.
     */
    public function handleCommandFinished(CommandFinished $event): void
    {
        try {
            $input_id = spl_object_id($event->input);
            $run = null;

            for ($index = count($this->runs) - 1; $index >= 0; $index--) {
                if ($this->runs[$index]['input_id'] === $input_id) {
                    $run = $this->runs[$index];
                    array_splice($this->runs, $index);

                    break;
                }
            }

            if ($run === null || $event->command === '' || ! $this->recorder->isEnabled()) {
                return;
            }

            $this->recorder->record(new CommandEventPayload(
                metadata: EventMetadata::capture($run['trace_id']),
                command: $event->command,
                arguments: $this->redactedArguments($event->input),
                exit_code: $event->exitCode,
                duration_ms: round(max(0.0, ((int) hrtime(true) - $run['started_at']) / 1_000_000), 3),
            ));
        } catch (Throwable) {
            return;
        }
    }

    /**
     * The run's argument and option names (except the `command` argument and the positional copy
     * of the command name Laravel adds under key 0) that have a value:
     * `true` for a flag that is set, the `"?"` placeholder for any other value. Unset values (null,
     * false, an empty array) are left out, and at most MAX_ARGUMENTS keys are kept.
     *
     * @return array<string, mixed>
     */
    private function redactedArguments(InputInterface $input): array
    {
        $arguments = [];

        foreach ([...$input->getArguments(), ...$input->getOptions()] as $name => $value) {
            if (! is_string($name) || $name === 'command' || $value === null || $value === false || $value === []) {
                continue;
            }

            $arguments[$name] = $value === true ? true : self::REDACTED;

            if (count($arguments) >= self::MAX_ARGUMENTS) {
                break;
            }
        }

        return $arguments;
    }
}
