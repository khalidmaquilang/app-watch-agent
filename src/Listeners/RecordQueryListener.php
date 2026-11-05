<?php

declare(strict_types=1);

namespace AppWatch\Agent\Listeners;

use AppWatch\Agent\Payloads\EventMetadata;
use AppWatch\Agent\Payloads\QueryEventPayload;
use AppWatch\Agent\Recorder;
use AppWatch\Agent\Support\SqlNormalizer;
use AppWatch\Agent\Support\TraceContext;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Events\QueryExecuted;
use Throwable;

/**
 * Records every executed database query of the current trace with its normalized SQL. The bindings
 * are never read, and literals in the SQL itself are replaced with `?`. Queries outside a trace are
 * not recorded, and neither are queries on the agent's own telemetry connection (`app-watch.connection`)
 * unless that connection was also the app's default one when the trace started. Bound as a singleton, so a query run while
 * recording one cannot recurse.
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
        private readonly Repository $config,
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

            if ($this->isTelemetryConnection((string) $event->connectionName)) {
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

    /**
     * Whether the connection is the agent's dedicated telemetry connection. A telemetry connection
     * that is also the app's default (`APP_WATCH_DB_CONNECTION=pgsql`) carries the app's own queries,
     * so it is not treated as one. The default is the one captured when the trace started, so a
     * temporary switch inside the trace (`migrate --database=app_watch`, which `app-watch:install`
     * runs) does not make the telemetry connection look like the app's own.
     */
    private function isTelemetryConnection(string $connection_name): bool
    {
        $telemetry_connection = $this->config->get('app-watch.connection');

        return is_string($telemetry_connection)
            && $telemetry_connection === $connection_name
            && $telemetry_connection !== ($this->trace_context->defaultConnection() ?? $this->config->get('database.default'));
    }
}
