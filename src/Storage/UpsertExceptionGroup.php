<?php

declare(strict_types=1);

namespace AppWatch\Agent\Storage;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionResolverInterface;
use UnexpectedValueException;

/**
 * Creates or updates the exception group of a fingerprint with one atomic upsert.
 */
final class UpsertExceptionGroup
{
    /**
     * The length of the `exception_groups.file` column. Longer paths are cut for the group row only;
     * the fingerprint and the occurrence rows keep the full path.
     */
    public const int FILE_MAX_LENGTH = 255;

    /**
     * One atomic upsert keyed on the (environment_id, fingerprint) unique index. Postgres evaluates every
     * SET expression against the existing row, so the message switches to the new one only when the new
     * occurrence is older than the group's current first occurrence. A resolved group that receives an
     * occurrence recorded after its `resolved_at` is re-opened, exactly like App Watch's upsert.
     */
    public const string UPSERT_SQL = <<<'SQL'
        INSERT INTO exception_groups
            (environment_id, fingerprint, class, message, file, line, first_seen_at, last_seen_at, occurrences, resolved_at, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?)
        ON CONFLICT (environment_id, fingerprint) DO UPDATE SET
            message = CASE WHEN EXCLUDED.first_seen_at < exception_groups.first_seen_at
                THEN EXCLUDED.message ELSE exception_groups.message END,
            first_seen_at = LEAST(exception_groups.first_seen_at, EXCLUDED.first_seen_at),
            last_seen_at = GREATEST(exception_groups.last_seen_at, EXCLUDED.last_seen_at),
            occurrences = exception_groups.occurrences + EXCLUDED.occurrences,
            resolved_at = CASE WHEN exception_groups.resolved_at IS NOT NULL AND EXCLUDED.last_seen_at > exception_groups.resolved_at
                THEN NULL ELSE exception_groups.resolved_at END,
            updated_at = EXCLUDED.updated_at
        RETURNING id
        SQL;

    /**
     * The connections, the agent configuration and the fingerprint.
     */
    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly Repository $config,
        private readonly FingerprintException $fingerprint_exception,
    ) {}

    /**
     * Create or update the group of the exception in the environment with a single
     * `INSERT ... ON CONFLICT DO UPDATE` (race-safe, no read-then-write) and return the group id.
     *
     * `$count` occurrences are added at once. Pass the raw message of the earliest occurrence and its
     * time as `$first_seen_at`, and the latest occurrence time as `$last_seen_at` (defaults to
     * `$first_seen_at`). LEAST/GREATEST keep out-of-order events from moving them the wrong way.
     *
     * @throws UnexpectedValueException when the database does not return the group id
     */
    public function upsert(
        int $environment_id,
        string $class,
        string $message,
        string $file,
        int $line,
        CarbonImmutable $first_seen_at,
        ?CarbonImmutable $last_seen_at = null,
        int $count = 1,
    ): int {
        $now = CarbonImmutable::now()->format('Y-m-d H:i:s.uP');
        $name = $this->config->get('app-watch.connection');
        $connection = $this->connections->connection(is_string($name) && $name !== '' ? $name : null);

        $id = $connection->scalar(self::UPSERT_SQL, [
            $environment_id,
            $this->fingerprint_exception->fingerprint($class, $file, $line, $message),
            $class,
            $message,
            mb_substr($file, 0, self::FILE_MAX_LENGTH),
            $line,
            $first_seen_at->format('Y-m-d H:i:s.uP'),
            ($last_seen_at ?? $first_seen_at)->format('Y-m-d H:i:s.uP'),
            $count,
            $now,
            $now,
        ]);

        if (! is_numeric($id)) {
            throw new UnexpectedValueException('The exception group upsert did not return an id.');
        }

        return (int) $id;
    }
}
