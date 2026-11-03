<?php

declare(strict_types=1);

use AppWatch\Agent\Enums\JobStatusEnum;
use AppWatch\Agent\Enums\LogLevelEnum;
use AppWatch\Agent\Payloads\EventMetadata;
use AppWatch\Agent\Payloads\EventPayload;
use AppWatch\Agent\Payloads\ExceptionEventPayload;
use AppWatch\Agent\Payloads\JobEventPayload;
use AppWatch\Agent\Payloads\LogEventPayload;
use AppWatch\Agent\Payloads\QueryEventPayload;
use AppWatch\Agent\Payloads\RequestEventPayload;
use AppWatch\Agent\Recorder;
use AppWatch\Agent\Tests\TestCase;
use AppWatch\Agent\Transport\FakeTransport;
use AppWatch\Agent\Transport\Transport;
use Illuminate\Http\Client\Request;

pest()->extend(TestCase::class)->in(__DIR__);

/**
 * Every event of the trace: those already flushed to the fake transport (when its request, job
 * run or command ended), then those still buffered in the recorder (which are flushed now).
 *
 * @return array<int, EventPayload>
 */
function recordedEvents(string $trace_id): array
{
    $transport = app(Transport::class);
    $sent = $transport instanceof FakeTransport ? $transport->events($trace_id) : [];

    return [...$sent, ...app(Recorder::class)->flush($trace_id)];
}

/**
 * A local TCP server on an ephemeral port standing in for the agent daemon, with its port.
 *
 * @return array{0: resource, 1: int}
 */
function startTestDaemon(): array
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $error_code, $error_message);

    if (! is_resource($server)) {
        throw new RuntimeException("Could not start the test daemon: {$error_message}");
    }

    $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);

    return [$server, $port];
}

/**
 * A port nothing listens on (a test server's port, freed again).
 */
function unusedPort(): int
{
    [$server, $port] = startTestDaemon();
    fclose($server);

    return $port;
}

/**
 * Accept every connection already made to the test daemon and decode the NDJSON lines it got,
 * one array per connection. The client has already written and closed, so this never blocks
 * for long.
 *
 * @param  resource  $server
 * @return array<int, array<int, array<string, mixed>>>
 */
function readTestDaemon($server): array
{
    $connections = [];

    while (($connection = @stream_socket_accept($server, 0.1)) !== false) {
        $lines = array_filter(explode("\n", (string) stream_get_contents($connection)), fn (string $line): bool => $line !== '');
        $connections[] = array_values(array_map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines));
        fclose($connection);
    }

    return $connections;
}

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

/**
 * The v1 contract fixture batch (tests/Fixtures/ingest-batch-v1.json).
 *
 * @return array{sent_at: string, environment: array<string, string>, events: array<int, array<string, mixed>>}
 */
function fixtureBatch(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/Fixtures/ingest-batch-v1.json'), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The given number of v1 events (copies of the fixture's request event, numbered by path).
 *
 * @return array<int, array<string, mixed>>
 */
function daemonEvents(int $count): array
{
    $event = fixtureEvent('request');

    if ($count < 1) {
        return [];
    }

    return array_map(static fn (int $index): array => [...$event, 'path' => "/orders/{$index}"], range(1, $count));
}

/**
 * The batch an ingest request carried: its gzip body, decoded.
 *
 * @return array<string, mixed>
 */
function ingestBatch(Request $request): array
{
    $json = gzdecode($request->body());

    if ($json === false) {
        throw new RuntimeException('The ingest request body is not gzip.');
    }

    return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Configure a token and monitor URL so the daemon can deliver.
 */
function configureMonitor(): void
{
    config([
        'app-watch.token' => 'aw_test_token',
        'app-watch.monitor_url' => 'https://monitor.test',
    ]);
}
