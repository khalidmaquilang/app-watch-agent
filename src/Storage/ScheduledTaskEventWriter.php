<?php

declare(strict_types=1);

namespace AppWatch\Agent\Storage;

/**
 * Writes scheduled task run events to the `scheduled_task_runs` table.
 */
final class ScheduledTaskEventWriter extends EventWriter
{
    /**
     * The raw telemetry table the events go to.
     */
    protected function table(): string
    {
        return 'scheduled_task_runs';
    }

    /**
     * The type-specific columns of one event's row. `timezone` and `exit_code` stay null when the
     * task has none (closures and jobs have no exit code).
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    protected function columns(array $event): array
    {
        return [
            'task' => $event['task'] ?? null,
            'kind' => $event['kind'] ?? null,
            'expression' => $event['expression'] ?? null,
            'timezone' => $event['timezone'] ?? null,
            'outcome' => $event['outcome'] ?? null,
            'exit_code' => $event['exit_code'] ?? null,
            'duration_ms' => $event['duration_ms'] ?? null,
            'without_overlapping' => $event['without_overlapping'] ?? false,
            'on_one_server' => $event['on_one_server'] ?? false,
        ];
    }
}
