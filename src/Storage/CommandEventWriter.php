<?php

declare(strict_types=1);

namespace AppWatch\Agent\Storage;

/**
 * Writes Artisan command run events to the `command_runs` table; `arguments` is stored as jsonb.
 */
final class CommandEventWriter extends EventWriter
{
    /**
     * The raw telemetry table the events go to.
     */
    protected function table(): string
    {
        return 'command_runs';
    }

    /**
     * The type-specific columns of one event's row. Missing or empty `arguments` are stored as null.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     *
     * @throws \JsonException when the arguments cannot be encoded
     */
    protected function columns(array $event): array
    {
        $arguments = $event['arguments'] ?? null;

        return [
            'command' => $event['command'] ?? null,
            'arguments' => $arguments === null || $arguments === [] ? null : $this->json($arguments),
            'exit_code' => $event['exit_code'] ?? null,
            'failed' => $event['failed'] ?? null,
            'duration_ms' => $event['duration_ms'] ?? null,
        ];
    }
}
