<?php

declare(strict_types=1);

use AppWatch\Agent\Daemon\Daemon;
use AppWatch\Agent\Daemon\WriteResult;
use AppWatch\Agent\Transport\DaemonTransport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    prepareTelemetryDatabase();

    $this->daemon = app(Daemon::class);
    $this->daemon->listen('127.0.0.1', 0);
    $this->port = (int) substr((string) strrchr((string) $this->daemon->address(), ':'), 1);
});

afterEach(function (): void {
    $this->daemon->close();
    restoreTelemetryConnection();
    dropTelemetrySchema();
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});

/**
 * The number of rows stored over every telemetry table.
 */
function storedTelemetryRows(): int
{
    return array_sum(telemetryRowCounts());
}

/**
 * Connect to the daemon, write the payload (optionally in several writes) and close.
 *
 * @param  array<int, string>  $chunks
 */
function sendToDaemon(int $port, array $chunks): void
{
    $client = stream_socket_client("tcp://127.0.0.1:{$port}", $error_code, $error_message, 1.0);

    if (! is_resource($client)) {
        throw new RuntimeException("Could not connect to the daemon: {$error_message}");
    }

    foreach ($chunks as $chunk) {
        fwrite($client, $chunk);
        fflush($client);
    }

    fclose($client);
}

/**
 * The events as NDJSON.
 *
 * @param  array<int, array<string, mixed>>  $events
 */
function ndjson(array $events): string
{
    return implode('', array_map(static fn (array $event): string => json_encode($event, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)."\n", $events));
}

/**
 * Tick the daemon until the condition holds (at most the given number of ticks), returning every
 * delivery attempt's result.
 *
 * @return array<int, WriteResult>
 */
function tickDaemonUntil(Daemon $daemon, Closure $condition, int $max_ticks = 20): array
{
    $results = [];

    for ($tick = 0; $tick < $max_ticks && ! $condition(); $tick++) {
        $result = $daemon->tick();

        if ($result !== null) {
            $results[] = $result;
        }
    }

    return $results;
}

it('listens on the configured address', function (): void {
    expect($this->daemon->address())->toBe("127.0.0.1:{$this->port}");
});

it('writes events pushed through the socket to the raw tables under the configured environment', function (): void {
    $events = daemonEvents(500);

    sendToDaemon($this->port, [ndjson($events)]);
    $results = tickDaemonUntil($this->daemon, fn (): bool => storedTelemetryRows() > 0);
    $results = [...$results, ...tickDaemonUntil($this->daemon, fn (): bool => false, 2)];

    expect($results)->toHaveCount(1)
        ->and($results[0])->toBeInstanceOf(WriteResult::class)
        ->and($results[0]->stored_count)->toBe(500)
        ->and($results[0]->shouldRetry())->toBeFalse()
        ->and(storedRequestPaths())->toBe(array_column($events, 'path'))
        ->and(DB::connection('app_watch')->table('requests')->distinct()->pluck('environment_id')->all())->toBe([1]);
});

it('writes fewer than 500 events once the flush interval has passed', function (): void {
    $events = daemonEvents(3);

    sendToDaemon($this->port, [ndjson($events)]);
    tickDaemonUntil($this->daemon, fn (): bool => $this->daemon->connectionCount() === 0 && storedTelemetryRows() === 0, 3);
    tickDaemonUntil($this->daemon, fn (): bool => false, 2);

    expect(storedTelemetryRows())->toBe(0);

    CarbonImmutable::setTestNow('2026-11-03 05:41:05');
    $result = $this->daemon->tick();

    expect($result?->stored_count)->toBe(3)
        ->and(storedRequestPaths())->toBe(['/orders/1', '/orders/2', '/orders/3']);
});

it('skips a malformed line and keeps processing the following ones', function (): void {
    config(['app-watch.daemon.flush_events' => 2]);
    $first = fixtureEvent('request');
    $second = fixtureEvent('query');

    sendToDaemon($this->port, [json_encode($first)."\n{\"type\": \"broken\n".json_encode($second)."\n"]);
    tickDaemonUntil($this->daemon, fn (): bool => storedTelemetryRows() > 0);

    expect($this->daemon->malformedCount())->toBe(1)
        ->and(telemetryRowCounts())->toMatchArray(['requests' => 1, 'queries' => 1]);
});

it('reassembles lines split across writes and collects events from several connections', function (): void {
    config(['app-watch.daemon.flush_events' => 3]);
    $line = json_encode(fixtureEvent('job'))."\n";

    sendToDaemon($this->port, [substr($line, 0, 20), substr($line, 20, 50), substr($line, 70)]);
    sendToDaemon($this->port, [ndjson([fixtureEvent('log'), fixtureEvent('cache')])]);
    tickDaemonUntil($this->daemon, fn (): bool => storedTelemetryRows() > 0);

    expect($this->daemon->malformedCount())->toBe(0)
        ->and(telemetryRowCounts())->toMatchArray(['job_runs' => 1, 'logs' => 1, 'cache_events' => 1]);
});

it('keeps listening and buffering while the database is down, and writes once it is back', function (): void {
    config(['app-watch.daemon.flush_events' => 1]);
    breakTelemetryConnection();

    sendToDaemon($this->port, [ndjson([fixtureEvent('request')])]);
    $results = tickDaemonUntil($this->daemon, fn (): bool => $this->daemon->outbox()->isBackingOff());

    expect($results)->toHaveCount(1)
        ->and($results[0]->shouldRetry())->toBeTrue()
        ->and($this->daemon->outbox()->nextAttemptAt()?->isFuture())->toBeTrue();

    sendToDaemon($this->port, [ndjson([fixtureEvent('log')])]);
    tickDaemonUntil($this->daemon, fn (): bool => count($this->daemon->outbox()->pending()) === 2);

    expect($this->daemon->outbox()->pending())->toHaveCount(2);

    restoreTelemetryConnection();
    CarbonImmutable::setTestNow('2026-11-03 05:41:01');
    $result = $this->daemon->tick();

    expect($result?->stored_count)->toBe(2)
        ->and(telemetryRowCounts())->toMatchArray(['requests' => 1, 'logs' => 1]);
});

it('throws when the port is already in use', function (): void {
    expect(fn () => app(Daemon::class)->listen('127.0.0.1', $this->port))
        ->toThrow(RuntimeException::class, "Could not listen on tcp://127.0.0.1:{$this->port}");
});

it('receives and writes what the app-side DaemonTransport sends', function (): void {
    config(['app-watch.daemon.host' => '127.0.0.1', 'app-watch.daemon.port' => $this->port, 'app-watch.daemon.flush_events' => 3]);
    // The transport's events are recorded at the real current time, so partition the real today.
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
    installTelemetrySchema();
    $trace_id = (string) Str::uuid();
    $events = [requestEvent($trace_id), queryEvent($trace_id), logEvent($trace_id)];

    app(DaemonTransport::class)->send($events);
    tickDaemonUntil($this->daemon, fn (): bool => storedTelemetryRows() > 0);

    expect(telemetryRowCounts())->toMatchArray(['requests' => 1, 'queries' => 1, 'logs' => 1])
        ->and(DB::connection('app_watch')->table('logs')->value('trace_id'))->toBe($trace_id);
});
