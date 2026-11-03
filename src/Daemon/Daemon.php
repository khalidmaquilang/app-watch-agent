<?php

declare(strict_types=1);

namespace AppWatch\Agent\Daemon;

use RuntimeException;

/**
 * The agent daemon's socket loop. It listens on TCP for the NDJSON events app processes send,
 * buffers them in the Batcher, hands due batches to the Outbox and lets the Outbox deliver them.
 * Every tick waits at most TICK_MICROSECONDS for socket activity, so flushes and retries are
 * never late by more than that, and a retry that is backing off never blocks reading.
 */
final class Daemon
{
    /**
     * The longest one tick waits for socket activity, in microseconds.
     */
    public const int TICK_MICROSECONDS = 250_000;

    /**
     * The most bytes read from a connection per fread() call.
     */
    public const int READ_CHUNK_BYTES = 65_536;

    /**
     * The listening socket; null until listen() succeeds.
     *
     * @var resource|null
     */
    private $server = null;

    /**
     * The open app connections, keyed by resource id.
     *
     * @var array<int, resource>
     */
    private array $connections = [];

    /**
     * Each open connection's partial-line buffer, keyed by resource id.
     *
     * @var array<int, LineBuffer>
     */
    private array $line_buffers = [];

    /**
     * Malformed lines from connections that have already closed.
     */
    private int $closed_malformed_count = 0;

    /**
     * The daemon's collaborators.
     */
    public function __construct(
        private readonly Batcher $batcher,
        private readonly Outbox $outbox,
    ) {}

    /**
     * Start listening on tcp://{host}:{port}.
     *
     * @throws RuntimeException when the address cannot be bound (e.g. the port is in use)
     */
    public function listen(string $host, int $port): void
    {
        $error_code = 0;
        $error_message = '';

        set_error_handler(static fn (): bool => true);

        try {
            $server = stream_socket_server("tcp://{$host}:{$port}", $error_code, $error_message);
        } finally {
            restore_error_handler();
        }

        if (! is_resource($server)) {
            throw new RuntimeException("Could not listen on tcp://{$host}:{$port}: ".($error_message !== '' ? $error_message : "error {$error_code}"));
        }

        stream_set_blocking($server, false);

        $this->server = $server;
    }

    /**
     * The address the daemon listens on, e.g. "127.0.0.1:2407"; null before listen().
     */
    public function address(): ?string
    {
        if (! is_resource($this->server)) {
            return null;
        }

        $address = stream_socket_get_name($this->server, false);

        return $address === false ? null : $address;
    }

    /**
     * Run one loop iteration: wait (at most TICK_MICROSECONDS) for connections and data, buffer
     * the received events, queue a due batch and try delivery. Returns the delivery attempt's
     * result, or null when none was made.
     */
    public function tick(): ?IngestResult
    {
        if (is_resource($this->server)) {
            $this->poll($this->server);
        }

        $this->queueDueBatch();

        return $this->outbox->deliver();
    }

    /**
     * Close every connection and the listening socket. Events still buffered stay in memory.
     */
    public function close(): void
    {
        foreach (array_keys($this->connections) as $connection_id) {
            $this->closeConnection($connection_id);
        }

        if (is_resource($this->server)) {
            fclose($this->server);
        }

        $this->server = null;
    }

    /**
     * Lines skipped because they could not be decoded into an event.
     */
    public function malformedCount(): int
    {
        $open_malformed_count = array_sum(array_map(
            static fn (LineBuffer $line_buffer): int => $line_buffer->malformedCount(),
            $this->line_buffers,
        ));

        return $this->closed_malformed_count + $open_malformed_count;
    }

    /**
     * The outbox the daemon delivers through.
     */
    public function outbox(): Outbox
    {
        return $this->outbox;
    }

    /**
     * How many app connections are open.
     */
    public function connectionCount(): int
    {
        return count($this->connections);
    }

    /**
     * Wait for activity on the listening socket and the open connections, then accept new
     * connections and read the ready ones.
     *
     * @param  resource  $server
     */
    private function poll($server): void
    {
        // Array spreading would renumber the integer keys; stream_select() keeps them.
        $read = $this->connections;
        $read[-1] = $server;
        $write = null;
        $except = null;

        set_error_handler(static fn (): bool => true);

        try {
            $ready = stream_select($read, $write, $except, 0, self::TICK_MICROSECONDS);
        } finally {
            restore_error_handler();
        }

        if ($ready === false || $ready === 0) {
            return;
        }

        foreach (array_keys($read) as $key) {
            if ($key === -1) {
                $this->accept($server);
            } else {
                $this->read($key);
            }
        }
    }

    /**
     * Accept every pending connection.
     *
     * @param  resource  $server
     */
    private function accept($server): void
    {
        set_error_handler(static fn (): bool => true);

        try {
            while (($connection = stream_socket_accept($server, 0)) !== false) {
                stream_set_blocking($connection, false);

                $connection_id = get_resource_id($connection);
                $this->connections[$connection_id] = $connection;
                $this->line_buffers[$connection_id] = new LineBuffer;
            }
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Read everything available on the connection, buffer its events, and close it at EOF.
     */
    private function read(int $connection_id): void
    {
        $connection = $this->connections[$connection_id] ?? null;
        $line_buffer = $this->line_buffers[$connection_id] ?? null;

        if (! is_resource($connection) || $line_buffer === null) {
            return;
        }

        while (true) {
            $chunk = fread($connection, self::READ_CHUNK_BYTES);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $this->buffer($line_buffer->push($chunk));
        }

        if (feof($connection)) {
            $this->buffer($line_buffer->finish());
            $this->closeConnection($connection_id);
        }
    }

    /**
     * Add events to the batcher, queueing a batch whenever one becomes due.
     *
     * @param  array<int, array<string, mixed>>  $events
     */
    private function buffer(array $events): void
    {
        foreach ($events as $event) {
            $this->batcher->add($event);

            if ($this->batcher->count() >= $this->batcher->flushEvents()) {
                $this->outbox->push($this->batcher->flush());
            }
        }
    }

    /**
     * Hand the batcher's events to the outbox if a batch is due.
     */
    private function queueDueBatch(): void
    {
        if ($this->batcher->shouldFlush()) {
            $this->outbox->push($this->batcher->flush());
        }
    }

    /**
     * Close one connection and keep its malformed-line count.
     */
    private function closeConnection(int $connection_id): void
    {
        $connection = $this->connections[$connection_id] ?? null;

        if (is_resource($connection)) {
            fclose($connection);
        }

        $this->closed_malformed_count += ($this->line_buffers[$connection_id] ?? new LineBuffer)->malformedCount();

        unset($this->connections[$connection_id], $this->line_buffers[$connection_id]);
    }
}
