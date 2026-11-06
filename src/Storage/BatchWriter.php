<?php

declare(strict_types=1);

namespace AppWatch\Agent\Storage;

use AppWatch\Agent\Enums\TelemetryTypeEnum;
use AppWatch\Agent\Support\AgentConfiguration;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionResolverInterface;
use RuntimeException;
use Throwable;

/**
 * Writes one batch of raw v1 events to the telemetry database, in one transaction.
 */
final class BatchWriter
{
    /**
     * The connections, the agent configuration, the validator and the per-type writers.
     */
    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly Repository $config,
        private readonly AgentConfiguration $agent_configuration,
        private readonly EventValidator $event_validator,
        private readonly RequestEventWriter $request_event_writer,
        private readonly QueryEventWriter $query_event_writer,
        private readonly JobEventWriter $job_event_writer,
        private readonly OutgoingRequestEventWriter $outgoing_request_event_writer,
        private readonly CacheEventWriter $cache_event_writer,
        private readonly LogEventWriter $log_event_writer,
        private readonly ExceptionEventWriter $exception_event_writer,
        private readonly CommandEventWriter $command_event_writer,
    ) {}

    /**
     * Store every event of the batch in its raw telemetry table for the configured environment
     * (`app-watch.environment_id`; any environment in an event is ignored).
     *
     * Events that break the v1 contract are dropped and counted as malformed. Events whose `recorded_at`
     * is outside [now − retention_days, now + partitions_ahead_days] have no partition, so they are dropped
     * and counted as skipped. The rest are grouped by type and written in ONE transaction on the agent's
     * connection: a database error in any type rolls back the whole batch (exception group upserts
     * included) and is rethrown for the daemon to classify. Types without events run no query, and a batch
     * without storable events opens no transaction.
     *
     * @param  array<array-key, mixed>  $events
     *
     * @throws RuntimeException when no environment id is configured
     * @throws Throwable when a write fails (the whole batch is rolled back)
     */
    public function write(array $events): WriteStats
    {
        $environment_id = $this->agent_configuration->environmentId();

        if ($environment_id === null) {
            throw new RuntimeException('app-watch.environment_id is not configured.');
        }

        $now = CarbonImmutable::now();
        $oldest_allowed = $now->subDays($this->integer('app-watch.retention_days', 14));
        $newest_allowed = $now->addDays($this->integer('app-watch.partitions_ahead_days', Schema::DEFAULT_PARTITIONS_AHEAD_DAYS));

        $groups = array_fill_keys(array_keys(EventValidator::FIELDS), []);
        $malformed = 0;
        $skipped = 0;

        foreach ($events as $event) {
            $validated = $this->event_validator->validate($event);
            $recorded_at = $validated === null ? null : EventValidator::parseRecordedAt($validated['recorded_at']);

            if ($validated === null || $recorded_at === null || ! is_string($validated['type'])) {
                $malformed++;

                continue;
            }

            if (! $recorded_at->betweenIncluded($oldest_allowed, $newest_allowed)) {
                $skipped++;

                continue;
            }

            $groups[$validated['type']][] = $validated;
        }

        $stored = array_map(static fn (): int => 0, $groups);

        if (array_filter($groups) !== []) {
            $name = $this->config->get('app-watch.connection');

            $this->connections->connection(is_string($name) && $name !== '' ? $name : null)->transaction(
                function () use ($groups, $environment_id, &$stored): void {
                    foreach ($groups as $type => $group) {
                        if ($group !== []) {
                            $stored[$type] = $this->writer($type)->write($environment_id, $group);
                        }
                    }
                },
            );
        }

        return new WriteStats($stored, $skipped, $malformed);
    }

    /**
     * The writer of an event type.
     */
    private function writer(string $type): EventWriter
    {
        return match (TelemetryTypeEnum::from($type)) {
            TelemetryTypeEnum::Request => $this->request_event_writer,
            TelemetryTypeEnum::Query => $this->query_event_writer,
            TelemetryTypeEnum::Job => $this->job_event_writer,
            TelemetryTypeEnum::OutgoingRequest => $this->outgoing_request_event_writer,
            TelemetryTypeEnum::Cache => $this->cache_event_writer,
            TelemetryTypeEnum::Log => $this->log_event_writer,
            TelemetryTypeEnum::Exception => $this->exception_event_writer,
            TelemetryTypeEnum::Command => $this->command_event_writer,
        };
    }

    /**
     * A non-negative integer setting, or the default.
     */
    private function integer(string $key, int $default): int
    {
        $value = $this->config->get($key, $default);

        return is_numeric($value) ? max(0, (int) $value) : $default;
    }
}
