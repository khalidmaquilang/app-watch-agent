<?php

declare(strict_types=1);

namespace AppWatch\Agent\Storage;

/**
 * Writes outgoing HTTP request events to the `outgoing_requests` table.
 */
final class OutgoingRequestEventWriter extends EventWriter
{
    /**
     * The raw telemetry table the events go to.
     */
    protected function table(): string
    {
        return 'outgoing_requests';
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
            'target_host' => $event['target_host'] ?? null,
            'url' => $event['url'] ?? null,
            'status_code' => $event['status_code'] ?? null,
            'failed' => $event['failed'] ?? null,
            'duration_ms' => $event['duration_ms'] ?? null,
        ];
    }
}
