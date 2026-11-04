<?php

declare(strict_types=1);

namespace AppWatch\Agent\Storage;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * Bulk inserts validated v1 events of one type (EventValidator output, or raw contract arrays) into
 * their raw telemetry table on the agent's connection (`app-watch.connection`).
 */
abstract class EventWriter
{
    /**
     * The most rows inserted by one INSERT statement. Keeps a statement far below Postgres' limit of
     * 65 535 bound parameters whatever the batch size is.
     */
    public const int CHUNK_SIZE = 500;

    /**
     * The connections and the agent configuration.
     */
    public function __construct(
        protected readonly ConnectionResolverInterface $connections,
        protected readonly Repository $config,
    ) {}

    /**
     * The raw telemetry table the events go to.
     */
    abstract protected function table(): string;

    /**
     * The type-specific columns of one event's row.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    abstract protected function columns(array $event): array;

    /**
     * Insert one row per event for the given environment, CHUNK_SIZE rows per INSERT, and return
     * how many rows were inserted. The environment never comes from the event. An empty list runs
     * no query. Database errors (e.g. SQLSTATE 23514 for a day without a partition) propagate.
     *
     * @param  array<int, array<string, mixed>>  $events
     */
    public function write(int $environment_id, array $events): int
    {
        $rows = [];

        foreach ($events as $event) {
            $rows[] = [...$this->commonColumns($environment_id, $event), ...$this->columns($event)];
        }

        $this->insert($rows);

        return count($rows);
    }

    /**
     * The telemetry connection.
     */
    protected function connection(): ConnectionInterface
    {
        $name = $this->config->get('app-watch.connection');

        return $this->connections->connection(is_string($name) && $name !== '' ? $name : null);
    }

    /**
     * The columns every raw table has.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    protected function commonColumns(int $environment_id, array $event): array
    {
        return [
            'environment_id' => $environment_id,
            'trace_id' => $event['trace_id'] ?? null,
            'recorded_at' => $event['recorded_at'] ?? null,
            'host' => $event['host'] ?? null,
            'deploy' => $event['deploy'] ?? null,
        ];
    }

    /**
     * Insert the rows into the table, CHUNK_SIZE rows per statement.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function insert(array $rows): void
    {
        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            $this->connection()->table($this->table())->insert($chunk);
        }
    }

    /**
     * JSON for a jsonb column; invalid UTF-8 is replaced instead of failing the batch.
     *
     * @throws \JsonException when the value cannot be encoded (e.g. nested too deep)
     */
    protected function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
