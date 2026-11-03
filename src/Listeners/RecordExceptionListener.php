<?php

declare(strict_types=1);

namespace AppWatch\Agent\Listeners;

use AppWatch\Agent\Payloads\EventMetadata;
use AppWatch\Agent\Payloads\ExceptionEventPayload;
use AppWatch\Agent\Payloads\ExceptionTraceFrame;
use AppWatch\Agent\Recorder;
use AppWatch\Agent\Support\TraceContext;
use Throwable;

/**
 * Records the exceptions the app's exception handler reports during a trace (registered as a
 * reportable callback, so exceptions the handler does not report — `dontReport`, `throttle`, or an
 * exception whose own report() method handles it — are not recorded). Records the class, message,
 * file, line and at most 100 stack frames (file, line, function, class — never the arguments).
 * Exceptions are recorded even in an unsampled trace, but not outside a trace. Bound as a
 * singleton, so an exception reported while one is being recorded cannot recurse.
 *
 * `handled` is a best-effort heuristic: true when the global `report()` helper (also used by
 * `report_if()`, `report_unless()` and `rescue()`) is on the call stack, i.e. the app reported the
 * exception itself and carried on; false when the framework reported it because it reached the top
 * level (the HTTP / console kernel, the queue worker).
 */
final class RecordExceptionListener
{
    /**
     * The longest message kept, in characters (the v1 contract limit).
     */
    public const int MAX_MESSAGE_LENGTH = 10000;

    /**
     * The longest file path kept, in bytes (the v1 contract limit).
     */
    public const int MAX_FILE_LENGTH = 2048;

    /**
     * The longest class name kept, in bytes (the v1 contract limit).
     */
    public const int MAX_CLASS_LENGTH = 255;

    /**
     * The longest function name kept, in bytes (the v1 contract limit).
     */
    public const int MAX_FUNCTION_LENGTH = 1024;

    /**
     * Whether an exception is being recorded right now.
     */
    private bool $is_recording = false;

    /**
     * The agent services the listener uses.
     */
    public function __construct(
        private readonly Recorder $recorder,
        private readonly TraceContext $trace_context,
    ) {}

    /**
     * Record the reported exception. Never returns false, so the handler goes on reporting it.
     */
    public function handle(Throwable $exception): void
    {
        if ($this->is_recording) {
            return;
        }

        $this->is_recording = true;

        try {
            $trace_id = $this->trace_context->traceId();

            if ($trace_id === null || ! $this->recorder->isEnabled()) {
                return;
            }

            $this->recorder->record(new ExceptionEventPayload(
                metadata: EventMetadata::capture($trace_id),
                class: $this->cut($exception::class, self::MAX_CLASS_LENGTH),
                message: mb_substr(mb_scrub($exception->getMessage(), 'UTF-8'), 0, self::MAX_MESSAGE_LENGTH, 'UTF-8'),
                file: $this->cut($exception->getFile(), self::MAX_FILE_LENGTH),
                line: max(0, $exception->getLine()),
                trace: $this->frames($exception),
                handled: $this->isReportedByHelper(),
            ));
        } catch (Throwable) {
            return;
        } finally {
            $this->is_recording = false;
        }
    }

    /**
     * The exception's first stack frames, without their arguments.
     *
     * @return array<int, ExceptionTraceFrame>
     */
    private function frames(Throwable $exception): array
    {
        $frames = [];

        foreach (array_slice($exception->getTrace(), 0, ExceptionEventPayload::MAX_TRACE_FRAMES) as $frame) {
            $file = $frame['file'] ?? null;
            $line = $frame['line'] ?? null;
            $class = $frame['class'] ?? null;

            $frames[] = new ExceptionTraceFrame(
                file: is_string($file) ? $this->cut($file, self::MAX_FILE_LENGTH) : null,
                line: is_int($line) ? max(0, $line) : null,
                function: $this->cut($frame['function'], self::MAX_FUNCTION_LENGTH),
                class: is_string($class) ? $this->cut($class, self::MAX_CLASS_LENGTH) : null,
            );
        }

        return $frames;
    }

    /**
     * Whether the exception is being reported through the global `report()` helper.
     */
    private function isReportedByHelper(): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if ($frame['function'] === 'report' && ! isset($frame['class'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The string as valid UTF-8, cut to the given number of bytes.
     */
    private function cut(string $value, int $max_bytes): string
    {
        return mb_strcut(mb_scrub($value, 'UTF-8'), 0, $max_bytes, 'UTF-8');
    }
}
