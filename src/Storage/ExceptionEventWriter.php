<?php

declare(strict_types=1);

namespace AppWatch\Agent\Storage;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionResolverInterface;
use UnexpectedValueException;

/**
 * Writes exception events to the `exception_occurrences` table, each linked to its upserted exception group.
 */
final class ExceptionEventWriter extends EventWriter
{
    /**
     * The connections, the agent configuration, the fingerprint and the group upsert.
     */
    public function __construct(
        ConnectionResolverInterface $connections,
        Repository $config,
        private readonly FingerprintException $fingerprint_exception,
        private readonly UpsertExceptionGroup $upsert_exception_group,
    ) {
        parent::__construct($connections, $config);
    }

    /**
     * Group the events by fingerprint and upsert each group ONCE with the number of its events (N identical
     * exceptions = one upsert with +N, the earliest event as first seen and the latest `recorded_at` as last
     * seen), then insert one `exception_occurrences` row per event linked to its group. Groups are upserted in
     * fingerprint order so concurrent batches lock group rows in the same order and cannot deadlock. Returns
     * the number of occurrences inserted. An empty list runs no query.
     *
     * @param  array<int, array<string, mixed>>  $events
     *
     * @throws UnexpectedValueException when an event lacks class/message/file/line/recorded_at or an upsert returns no id
     * @throws \JsonException when a trace cannot be encoded
     */
    public function write(int $environment_id, array $events): int
    {
        /** @var array<string, array{class: string, message: string, file: string, line: int, first_seen_at: CarbonImmutable, last_seen_at: CarbonImmutable, count: int}> $groups */
        $groups = [];
        $fingerprints = [];

        foreach ($events as $index => $event) {
            $class = $this->stringField($event, 'class');
            $message = $this->stringField($event, 'message');
            $file = $this->stringField($event, 'file');
            $line = $event['line'] ?? null;
            $recorded_at = $this->recordedAt($event);

            if (! is_int($line)) {
                throw new UnexpectedValueException('An exception event has no integer line.');
            }

            $fingerprint = $this->fingerprint_exception->fingerprint($class, $file, $line, $message);
            $fingerprints[$index] = $fingerprint;

            if (! isset($groups[$fingerprint])) {
                $groups[$fingerprint] = [
                    'class' => $class, 'message' => $message, 'file' => $file, 'line' => $line,
                    'first_seen_at' => $recorded_at, 'last_seen_at' => $recorded_at, 'count' => 0,
                ];
            }

            $groups[$fingerprint]['count']++;

            if ($recorded_at->lessThan($groups[$fingerprint]['first_seen_at'])) {
                $groups[$fingerprint]['first_seen_at'] = $recorded_at;
                $groups[$fingerprint]['message'] = $message;
            }

            if ($recorded_at->greaterThan($groups[$fingerprint]['last_seen_at'])) {
                $groups[$fingerprint]['last_seen_at'] = $recorded_at;
            }
        }

        ksort($groups, SORT_STRING);

        $group_ids = [];

        foreach ($groups as $fingerprint => $group) {
            $group_ids[$fingerprint] = $this->upsert_exception_group->upsert(
                $environment_id,
                $group['class'],
                $group['message'],
                $group['file'],
                $group['line'],
                $group['first_seen_at'],
                $group['last_seen_at'],
                $group['count'],
            );
        }

        $rows = [];

        foreach ($events as $index => $event) {
            $rows[] = [
                ...$this->commonColumns($environment_id, $event),
                'exception_group_id' => $group_ids[$fingerprints[$index]],
                ...$this->columns($event),
            ];
        }

        $this->insert($rows);

        return count($rows);
    }

    /**
     * The raw telemetry table the events go to.
     */
    protected function table(): string
    {
        return 'exception_occurrences';
    }

    /**
     * The type-specific columns of one event's row (without the group id).
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     *
     * @throws \JsonException when the trace cannot be encoded
     */
    protected function columns(array $event): array
    {
        return [
            'class' => $event['class'] ?? null,
            'message' => $event['message'] ?? null,
            'file' => $event['file'] ?? null,
            'line' => $event['line'] ?? null,
            'trace' => $this->json($event['trace'] ?? []),
            'handled' => $event['handled'] ?? null,
        ];
    }

    /**
     * A string field of the event.
     *
     * @param  array<string, mixed>  $event
     *
     * @throws UnexpectedValueException when the field is not a string
     */
    private function stringField(array $event, string $key): string
    {
        $value = $event[$key] ?? null;

        if (! is_string($value)) {
            throw new UnexpectedValueException("An exception event has no string {$key}.");
        }

        return $value;
    }

    /**
     * The event's `recorded_at` as a date.
     *
     * @param  array<string, mixed>  $event
     *
     * @throws UnexpectedValueException when `recorded_at` is not a date
     */
    private function recordedAt(array $event): CarbonImmutable
    {
        $recorded_at = EventValidator::parseRecordedAt($event['recorded_at'] ?? null);

        if ($recorded_at === null) {
            throw new UnexpectedValueException('An exception event has no valid recorded_at.');
        }

        return $recorded_at;
    }
}
