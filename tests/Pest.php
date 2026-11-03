<?php

declare(strict_types=1);

use AppWatch\Agent\Enums\JobStatusEnum;
use AppWatch\Agent\Enums\LogLevelEnum;
use AppWatch\Agent\Payloads\EventMetadata;
use AppWatch\Agent\Payloads\ExceptionEventPayload;
use AppWatch\Agent\Payloads\JobEventPayload;
use AppWatch\Agent\Payloads\LogEventPayload;
use AppWatch\Agent\Payloads\QueryEventPayload;
use AppWatch\Agent\Payloads\RequestEventPayload;
use AppWatch\Agent\Tests\TestCase;

pest()->extend(TestCase::class)->in(__DIR__);

/**
 * The event of the given type from the copied v1 contract fixture (tests/Fixtures/ingest-batch-v1.json).
 *
 * @return array<string, mixed>
 */
function fixtureEvent(string $type): array
{
    /** @var array{events: array<int, array<string, mixed>>} $batch */
    $batch = json_decode((string) file_get_contents(__DIR__.'/Fixtures/ingest-batch-v1.json'), true, flags: JSON_THROW_ON_ERROR);

    foreach ($batch['events'] as $event) {
        if ($event['type'] === $type) {
            return $event;
        }
    }

    throw new RuntimeException("No {$type} event in the fixture.");
}

/**
 * The common metadata of a fixture event.
 *
 * @param  array<string, mixed>  $event
 */
function fixtureMetadata(array $event): EventMetadata
{
    return new EventMetadata(
        trace_id: $event['trace_id'],
        recorded_at: new DateTimeImmutable($event['recorded_at']),
        host: $event['host'],
        deploy: $event['deploy'],
    );
}

/**
 * Assert the payload serializes to exactly the fixture event: same keys, order, types and values.
 *
 * @param  array<string, mixed>  $payload
 * @param  array<string, mixed>  $fixture
 */
function expectMatchesContract(array $payload, array $fixture): void
{
    expect(array_keys($payload))->toBe(array_keys($fixture))
        ->and(array_map(get_debug_type(...), $payload))->toBe(array_map(get_debug_type(...), $fixture))
        ->and($payload)->toBe($fixture);
}

/**
 * A request event for the given trace.
 */
function requestEvent(string $trace_id, string $path = '/orders/42'): RequestEventPayload
{
    return new RequestEventPayload(EventMetadata::capture($trace_id), 'GET', null, $path, 200, 10.0, null);
}

/**
 * A query event for the given trace.
 */
function queryEvent(string $trace_id, string $sql = 'select * from "orders"'): QueryEventPayload
{
    return new QueryEventPayload(EventMetadata::capture($trace_id), 'sqlite', $sql, sha1($sql), 1.0);
}

/**
 * A job event for the given trace.
 */
function jobEvent(string $trace_id, string $job_class = 'App\\Jobs\\SendOrderConfirmationJob'): JobEventPayload
{
    return new JobEventPayload(EventMetadata::capture($trace_id), $job_class, 'default', 'sync', JobStatusEnum::Processed, 1, 5.0);
}

/**
 * A log event for the given trace.
 */
function logEvent(string $trace_id, LogLevelEnum $level = LogLevelEnum::Error): LogEventPayload
{
    return new LogEventPayload(EventMetadata::capture($trace_id), $level, 'Something happened', null);
}

/**
 * An exception event for the given trace.
 */
function exceptionEvent(string $trace_id): ExceptionEventPayload
{
    return new ExceptionEventPayload(EventMetadata::capture($trace_id), RuntimeException::class, 'Boom', '/app/a.php', 1, [], false);
}
