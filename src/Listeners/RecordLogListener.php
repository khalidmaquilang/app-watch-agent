<?php

declare(strict_types=1);

namespace AppWatch\Agent\Listeners;

use AppWatch\Agent\Enums\LogLevelEnum;
use AppWatch\Agent\Payloads\EventMetadata;
use AppWatch\Agent\Payloads\LogEventPayload;
use AppWatch\Agent\Recorder;
use AppWatch\Agent\Support\IgnoreRules;
use AppWatch\Agent\Support\LogContextSanitizer;
use AppWatch\Agent\Support\TraceContext;
use Illuminate\Log\Events\MessageLogged;
use Throwable;

/**
 * Records the log messages of the current trace at or above the configured `log_level`: the level,
 * the message (cut to 10 000 characters) and the sanitized context (see LogContextSanitizer).
 * Messages outside a trace are not recorded. Bound as a singleton, so a message logged while one is
 * being recorded (e.g. by a context object's __toString or jsonSerialize) is ignored instead of
 * recursing.
 */
final class RecordLogListener
{
    /**
     * The longest message kept, in characters (the v1 contract limit).
     */
    public const int MAX_MESSAGE_LENGTH = 10000;

    /**
     * Whether a message is being recorded right now.
     */
    private bool $is_recording = false;

    /**
     * The agent services the listener uses.
     */
    public function __construct(
        private readonly Recorder $recorder,
        private readonly TraceContext $trace_context,
        private readonly IgnoreRules $ignore_rules,
        private readonly LogContextSanitizer $context_sanitizer,
    ) {}

    /**
     * Record the logged message.
     */
    public function handle(MessageLogged $event): void
    {
        if ($this->is_recording) {
            return;
        }

        $this->is_recording = true;

        try {
            $level = LogLevelEnum::tryFrom(strtolower((string) $event->level));
            $trace_id = $this->trace_context->traceId();

            if (
                $level === null
                || $trace_id === null
                || ! $this->trace_context->isSampled()
                || ! $this->recorder->isEnabled()
                || $this->ignore_rules->shouldIgnoreLogLevel($level)
            ) {
                return;
            }

            $this->recorder->record(new LogEventPayload(
                metadata: EventMetadata::capture($trace_id),
                level: $level,
                message: mb_substr(mb_scrub((string) $event->message, 'UTF-8'), 0, self::MAX_MESSAGE_LENGTH, 'UTF-8'),
                context: $this->context_sanitizer->sanitize($event->context),
            ));
        } catch (Throwable) {
            return;
        } finally {
            $this->is_recording = false;
        }
    }
}
