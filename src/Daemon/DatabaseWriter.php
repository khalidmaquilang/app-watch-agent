<?php

declare(strict_types=1);

namespace AppWatch\Agent\Daemon;

use AppWatch\Agent\Storage\BatchWriter;
use AppWatch\Agent\Storage\WriteStats;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use PDOException;
use Throwable;

/**
 * Writes the daemon's events to the telemetry database through the BatchWriter, in chunks of at
 * most MAX_EVENTS_PER_BATCH, each in its own transaction with a statement timeout.
 *
 * A chunk the database rejects with an SQLSTATE of class 22 (data exception) or 23 (integrity
 * constraint violation, which includes 23514 "no partition of relation found for row") is poison:
 * retrying cannot help, so it is dropped and counted and the next chunk is written. Every other
 * failure — connection errors (08), operator intervention and timeouts (57), insufficient resources
 * (53), deadlocks (40P01), invalid transaction state (25), or anything without an SQLSTATE — is
 * retryable: writing stops at that chunk and it (and every later one) is handed back as pending.
 */
final class DatabaseWriter
{
    /**
     * The most events written in one transaction.
     */
    public const int MAX_EVENTS_PER_BATCH = 1000;

    /**
     * How long one statement may run by default, in seconds.
     */
    public const int TIMEOUT_SECONDS = 10;

    /**
     * The SQLSTATE classes whose chunk is poison: dropped, never retried.
     *
     * @var array<int, string>
     */
    public const array POISON_SQLSTATE_CLASSES = ['22', '23'];

    /**
     * The SQLSTATE Postgres raises when no partition exists for a row's `recorded_at`.
     */
    public const string CHECK_VIOLATION = '23514';

    /**
     * The longest failure message kept, in characters.
     */
    public const int MAX_FAILURE_LENGTH = 300;

    /**
     * The writer's collaborators.
     */
    public function __construct(
        private readonly BatchWriter $batch_writer,
        private readonly ConnectionResolverInterface $connections,
        private readonly Repository $config,
    ) {}

    /**
     * Write the events in chunks of at most MAX_EVENTS_PER_BATCH, dropping poison chunks and
     * stopping at the first chunk that should be retried. Each statement may run at most the
     * given number of seconds.
     *
     * @param  array<int, array<string, mixed>>  $events
     */
    public function send(array $events, int $timeout_seconds = self::TIMEOUT_SECONDS): WriteResult
    {
        $chunks = array_chunk($events, self::MAX_EVENTS_PER_BATCH);
        $stored_count = 0;
        $skipped_count = 0;
        $poison_count = 0;
        $poison_sqlstate = null;

        foreach ($chunks as $index => $chunk) {
            try {
                $stats = $this->write($chunk, $timeout_seconds);
            } catch (Throwable $exception) {
                $sqlstate = $this->sqlState($exception);

                if ($sqlstate !== null && in_array(substr($sqlstate, 0, 2), self::POISON_SQLSTATE_CLASSES, true)) {
                    $poison_count += count($chunk);
                    $poison_sqlstate = $sqlstate;

                    continue;
                }

                $this->disconnect();

                return new WriteResult(
                    stored_count: $stored_count,
                    skipped_count: $skipped_count,
                    poison_count: $poison_count,
                    poison_sqlstate: $poison_sqlstate,
                    pending: array_merge(...array_slice($chunks, $index)),
                    failure: $this->failure($exception, $sqlstate),
                );
            }

            $stored_count += $stats->storedCount();
            $skipped_count += $stats->skipped_retention + $stats->malformed;
        }

        return new WriteResult(
            stored_count: $stored_count,
            skipped_count: $skipped_count,
            poison_count: $poison_count,
            poison_sqlstate: $poison_sqlstate,
        );
    }

    /**
     * Write one chunk in a transaction whose statements may run at most the given number of
     * seconds (`SET LOCAL statement_timeout`, Postgres only). The BatchWriter's own transaction
     * becomes a savepoint inside it.
     *
     * @param  array<int, array<string, mixed>>  $chunk
     *
     * @throws Throwable when the chunk cannot be written
     */
    private function write(array $chunk, int $timeout_seconds): WriteStats
    {
        $connection = $this->connection();

        if (! $connection instanceof Connection || $connection->getDriverName() !== 'pgsql') {
            return $this->batch_writer->write($chunk);
        }

        return $connection->transaction(function (Connection $connection) use ($chunk, $timeout_seconds): WriteStats {
            $connection->statement('SET LOCAL statement_timeout = '.(max(1, $timeout_seconds) * 1000));

            return $this->batch_writer->write($chunk);
        });
    }

    /**
     * The telemetry connection (`app-watch.connection`).
     */
    private function connection(): ConnectionInterface
    {
        $name = $this->config->get('app-watch.connection');

        return $this->connections->connection(is_string($name) && $name !== '' ? $name : null);
    }

    /**
     * Drop the telemetry connection after a retryable failure, so the next attempt reconnects
     * instead of reusing a broken or aborted session.
     */
    private function disconnect(): void
    {
        try {
            $connection = $this->connection();

            if ($connection instanceof Connection) {
                $connection->disconnect();
            }
        } catch (Throwable) {
            // The connection could not even be resolved; there is nothing to drop.
        }
    }

    /**
     * The SQLSTATE of the exception or one it wraps, or null when it has none.
     */
    private function sqlState(Throwable $exception): ?string
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof PDOException) {
                $candidates = [$current->errorInfo[0] ?? null, $current->getCode()];

                foreach ($candidates as $candidate) {
                    if (is_string($candidate) && $candidate !== '00000' && preg_match('/^[0-9A-Z]{5}$/', $candidate) === 1) {
                        return $candidate;
                    }
                }
            }

            if (preg_match('/SQLSTATE\[([0-9A-Z]{5})\]/', $current->getMessage(), $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * A short, credential-free reason for the failure: the first line of the innermost error
     * (never the query, its bindings or the connection details Laravel appends), with any
     * `password=...` removed, at most MAX_FAILURE_LENGTH characters.
     */
    private function failure(Throwable $exception, ?string $sqlstate): string
    {
        $innermost = $exception;

        while ($innermost->getPrevious() !== null) {
            $innermost = $innermost->getPrevious();
        }

        $message = trim(strtok($innermost->getMessage(), "\n") ?: '');
        $message = (string) preg_replace('/password\s*=\s*\S+/i', 'password=***', $message);

        if ($message === '') {
            $message = $innermost::class;
        }

        if ($sqlstate !== null && ! str_contains($message, $sqlstate)) {
            $message = "SQLSTATE[{$sqlstate}] {$message}";
        }

        return mb_substr($message, 0, self::MAX_FAILURE_LENGTH);
    }
}
