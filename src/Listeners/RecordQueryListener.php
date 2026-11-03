<?php

declare(strict_types=1);

namespace AppWatch\Agent\Listeners;

use AppWatch\Agent\Payloads\EventMetadata;
use AppWatch\Agent\Payloads\QueryEventPayload;
use AppWatch\Agent\Recorder;
use AppWatch\Agent\Support\SqlNormalizer;
use AppWatch\Agent\Support\TraceContext;
use Illuminate\Database\Events\QueryExecuted;
use Throwable;

/**
 * Records every executed database query of the current trace with its normalized SQL. The bindings
 * are never read, and literals in the SQL itself are replaced with `?`. Queries outside a trace are
 * not recorded. Bound as a singleton, so a query run while recording one cannot recurse.
 */
final class RecordQueryListener
{
    /**
     * Whether a query is being recorded right now.
     */
    private bool $is_recording = false;

    /**
     * The agent services the listener uses.
     */
    public function __construct(
        private readonly Recorder $recorder,
        private readonly TraceContext $trace_context,
        private readonly SqlNormalizer $sql_normalizer,
    ) {}

    /**
     * Record the executed query.
     */
    public function handle(QueryExecuted $event): void
    {
        if ($this->is_recording) {
            return;
        }

        $this->is_recording = true;

        try {
            $trace_id = $this->trace_context->traceId();

            if ($trace_id === null || ! $this->trace_context->isSampled() || ! $this->recorder->isEnabled()) {
                return;
            }

            $sql = $this->sql_normalizer->normalize((string) $event->sql);

            if ($sql === null || $sql === '') {
                return;
            }

            $this->recorder->record(new QueryEventPayload(
                metadata: EventMetadata::capture($trace_id),
                connection: (string) $event->connectionName,
                sql: $sql,
                sql_hash: $this->sql_normalizer->hash($sql),
                duration_ms: round(max(0.0, (float) $event->time), 3),
            ));
        } catch (Throwable) {
            return;
        } finally {
            $this->is_recording = false;
        }
    }
}
