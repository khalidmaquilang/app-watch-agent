<?php

declare(strict_types=1);

namespace AppWatch\Agent\Storage;

/**
 * Writes query events to the `queries` table.
 */
final class QueryEventWriter extends EventWriter
{
    /**
     * The raw telemetry table the events go to.
     */
    protected function table(): string
    {
        return 'queries';
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
            'connection' => $event['connection'] ?? null,
            'sql' => $event['sql'] ?? null,
            'sql_hash' => $event['sql_hash'] ?? null,
            'duration_ms' => $event['duration_ms'] ?? null,
        ];
    }
}
