<?php

declare(strict_types=1);

namespace AppWatch\Agent\Transport;

use AppWatch\Agent\Payloads\EventPayload;
use Illuminate\Contracts\Config\Repository;
use JsonException;
use Throwable;

/**
 * Sends events to the local agent daemon as newline-delimited JSON (one v1 event per line) over
 * one short-lived TCP connection per batch. Connecting gives up after 50 ms and writing after
 * 200 ms, so a missing or stuck daemon only costs the host app that much. Every failure is
 * swallowed and counted as dropped events; nothing is ever logged through the app logger, which
 * would feed back into the log listener.
 */
final class DaemonTransport implements Transport
{
    /**
     * How long connecting to the daemon may take, in seconds.
     */
    public const float CONNECT_TIMEOUT_SECONDS = 0.05;

    /**
     * How long writing one batch may take in total, in nanoseconds.
     */
    public const int WRITE_TIMEOUT_NANOSECONDS = 200_000_000;

    /**
     * The most bytes handed to one fwrite() call.
     */
    public const int WRITE_CHUNK_BYTES = 65_536;

    /**
     * Flags for encoding one event. Invalid UTF-8 is substituted, so one bad string cannot drop
     * the event (or the trace); zero fractions are kept so floats stay floats.
     */
    public const int JSON_FLAGS = JSON_THROW_ON_ERROR
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_PRESERVE_ZERO_FRACTION
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE;

    /**
     * Events that could not be encoded or sent since the transport was created.
     */
    private int $dropped_count = 0;

    /**
     * Read the daemon's address from the app-watch config.
     */
    public function __construct(
        private readonly Repository $config,
    ) {}

    /**
     * Encode the events and write them to the daemon; an event that cannot be encoded is
     * skipped, and a failed connection or write drops the whole batch.
     *
     * @param  array<int, EventPayload>  $events
     */
    public function send(array $events): void
    {
        if ($events === []) {
            return;
        }

        set_error_handler(static fn (): bool => true);

        try {
            $lines = $this->encode($events);

            if ($lines !== [] && ! $this->write(implode('', $lines))) {
                $this->dropped_count += count($lines);
            }
        } catch (Throwable) {
            $this->dropped_count += count($events);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Events that could not be encoded or sent since the transport was created.
     */
    public function droppedCount(): int
    {
        return $this->dropped_count;
    }

    /**
     * The events as newline-terminated JSON lines, skipping (and counting) any that cannot be
     * encoded.
     *
     * @param  array<int, EventPayload>  $events
     * @return array<int, string>
     */
    private function encode(array $events): array
    {
        $lines = [];

        foreach ($events as $event) {
            try {
                $lines[] = json_encode($event->toArray(), self::JSON_FLAGS)."\n";
            } catch (JsonException) {
                $this->dropped_count++;
            }
        }

        return $lines;
    }

    /**
     * Write the whole buffer to the daemon, looping over partial writes until it is written or
     * the write deadline passes. Returns whether everything was written.
     */
    private function write(string $buffer): bool
    {
        $host = $this->config->get('app-watch.daemon.host', '127.0.0.1');
        $port = $this->config->get('app-watch.daemon.port', 2407);

        if (! is_string($host) || ! is_numeric($port)) {
            return false;
        }

        $socket = stream_socket_client(
            "tcp://{$host}:{$port}",
            $error_code,
            $error_message,
            self::CONNECT_TIMEOUT_SECONDS,
        );

        if (! is_resource($socket)) {
            return false;
        }

        try {
            stream_set_blocking($socket, false);

            $deadline = (int) hrtime(true) + self::WRITE_TIMEOUT_NANOSECONDS;
            $length = strlen($buffer);
            $written = 0;

            while ($written < $length) {
                $bytes = fwrite($socket, substr($buffer, $written, self::WRITE_CHUNK_BYTES));

                if ($bytes === false) {
                    return false;
                }

                $written += $bytes;

                if ($written >= $length) {
                    break;
                }

                $remaining_microseconds = intdiv($deadline - (int) hrtime(true), 1000);

                if ($remaining_microseconds <= 0) {
                    return false;
                }

                $read = null;
                $write = [$socket];
                $except = null;

                if (stream_select($read, $write, $except, 0, $remaining_microseconds) !== 1) {
                    return false;
                }
            }

            return true;
        } finally {
            fclose($socket);
        }
    }
}
