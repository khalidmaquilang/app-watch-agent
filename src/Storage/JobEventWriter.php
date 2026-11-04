<?php

declare(strict_types=1);

namespace AppWatch\Agent\Storage;

/**
 * Writes job run events to the `job_runs` table.
 */
final class JobEventWriter extends EventWriter
{
    /**
     * The raw telemetry table the events go to.
     */
    protected function table(): string
    {
        return 'job_runs';
    }

    /**
     * The type-specific columns of one event's row.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    protected function columns(array $event): array
    {
        return [
            'job_class' => $event['job_class'] ?? null,
            'queue' => $event['queue'] ?? null,
            'connection' => $event['connection'] ?? null,
            'status' => $event['status'] ?? null,
            'attempt' => $event['attempt'] ?? null,
            'duration_ms' => $event['duration_ms'] ?? null,
        ];
    }
}
