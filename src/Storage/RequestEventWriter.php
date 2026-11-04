<?php

declare(strict_types=1);

namespace AppWatch\Agent\Storage;

/**
 * Writes request events to the `requests` table.
 */
final class RequestEventWriter extends EventWriter
{
    /**
     * The raw telemetry table the events go to.
     */
    protected function table(): string
    {
        return 'requests';
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
            'method' => $event['method'] ?? null,
            'route' => $event['route'] ?? null,
            'path' => $event['path'] ?? null,
            'status_code' => $event['status_code'] ?? null,
            'duration_ms' => $event['duration_ms'] ?? null,
            'memory_peak_bytes' => $event['memory_peak_bytes'] ?? null,
        ];
    }
}
