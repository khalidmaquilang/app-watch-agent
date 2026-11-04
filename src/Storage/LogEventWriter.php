<?php

declare(strict_types=1);

namespace AppWatch\Agent\Storage;

/**
 * Writes log events to the `logs` table; `context` is stored as jsonb.
 */
final class LogEventWriter extends EventWriter
{
    /**
     * The raw telemetry table the events go to.
     */
    protected function table(): string
    {
        return 'logs';
    }

    /**
     * The type-specific columns of one event's row.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     *
     * @throws \JsonException when the context cannot be encoded
     */
    protected function columns(array $event): array
    {
        $context = $event['context'] ?? null;

        return [
            'level' => $event['level'] ?? null,
            'message' => $event['message'] ?? null,
            'context' => $context === null ? null : $this->json($context),
        ];
    }
}
