<?php

declare(strict_types=1);

namespace AppWatch\Agent\Storage;

/**
 * Writes cache events to the `cache_events` table.
 */
final class CacheEventWriter extends EventWriter
{
    /**
     * The raw telemetry table the events go to.
     */
    protected function table(): string
    {
        return 'cache_events';
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
            'store' => $event['store'] ?? null,
            'key' => $event['key'] ?? null,
            'operation' => $event['operation'] ?? null,
            'duration_ms' => $event['duration_ms'] ?? null,
        ];
    }
}
