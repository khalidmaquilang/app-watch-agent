<?php

declare(strict_types=1);

use AppWatch\Agent\Daemon\Daemon;
use AppWatch\Agent\Daemon\IngestResult;
use AppWatch\Agent\Transport\DaemonTransport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    configureMonitor();
    Http::preventStrayRequests();

    $this->daemon = app(Daemon::class);
    $this->daemon->listen('127.0.0.1', 0);
    $this->port = (int) substr((string) strrchr((string) $this->daemon->address(), ':'), 1);
});

afterEach(function (): void {
    $this->daemon->close();
    CarbonImmutable::setTestNow();
});

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
 * @return array<int, IngestResult>
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

it('posts exactly one batch once 500 events arrive', function (): void {
    Http::fake(['monitor.test/*' => Http::response(status: 202)]);
    $events = daemonEvents(500);

    sendToDaemon($this->port, [ndjson($events)]);
    tickDaemonUntil($this->daemon, fn (): bool => Http::recorded()->isNotEmpty());
    tickDaemonUntil($this->daemon, fn (): bool => false, 2);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => ingestBatch($request)['events'] === $events
        && $request->header('Authorization') === ['Bearer aw_test_token']);
});

it('posts fewer than 500 events once the flush interval has passed', function (): void {
    Http::fake(['monitor.test/*' => Http::response(status: 202)]);
    CarbonImmutable::setTestNow('2026-11-03 05:41:00');
    $events = daemonEvents(3);

    sendToDaemon($this->port, [ndjson($events)]);
    tickDaemonUntil($this->daemon, fn (): bool => $this->daemon->connectionCount() === 0 && Http::recorded()->isEmpty(), 3);
    tickDaemonUntil($this->daemon, fn (): bool => false, 2);

    Http::assertNothingSent();

    CarbonImmutable::setTestNow('2026-11-03 05:41:05');
    $this->daemon->tick();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => ingestBatch($request)['events'] === $events);
});

it('skips a malformed line and keeps processing the following ones', function (): void {
    Http::fake(['monitor.test/*' => Http::response(status: 202)]);
    config(['app-watch.daemon.flush_events' => 2]);
    $first = fixtureEvent('request');
    $second = fixtureEvent('query');

    sendToDaemon($this->port, [json_encode($first)."\n{\"type\": \"broken\n".json_encode($second)."\n"]);
    tickDaemonUntil($this->daemon, fn (): bool => Http::recorded()->isNotEmpty());

    expect($this->daemon->malformedCount())->toBe(1);
    Http::assertSent(fn (Request $request): bool => ingestBatch($request)['events'] === [$first, $second]);
});

it('reassembles lines split across writes and collects events from several connections', function (): void {
    Http::fake(['monitor.test/*' => Http::response(status: 202)]);
    config(['app-watch.daemon.flush_events' => 3]);
    $line = json_encode(fixtureEvent('job'))."\n";

    sendToDaemon($this->port, [substr($line, 0, 20), substr($line, 20, 50), substr($line, 70)]);
    sendToDaemon($this->port, [ndjson([fixtureEvent('log'), fixtureEvent('cache')])]);
    tickDaemonUntil($this->daemon, fn (): bool => Http::recorded()->isNotEmpty());

    expect($this->daemon->malformedCount())->toBe(0);
    Http::assertSent(fn (Request $request): bool => collect(ingestBatch($request)['events'])->pluck('type')->sort()->values()->all() === ['cache', 'job', 'log']);
});

it('keeps reading while a failed delivery backs off', function (): void {
    CarbonImmutable::setTestNow('2026-11-03 05:41:00');
    config(['app-watch.daemon.flush_events' => 1]);
    Http::fake(['monitor.test/*' => Http::sequence()->push(status: 503)->push(status: 202)]);

    sendToDaemon($this->port, [ndjson([fixtureEvent('request')])]);
    tickDaemonUntil($this->daemon, fn (): bool => Http::recorded()->isNotEmpty());

    sendToDaemon($this->port, [ndjson([fixtureEvent('log')])]);
    tickDaemonUntil($this->daemon, fn (): bool => count($this->daemon->outbox()->pending()) === 2);

    Http::assertSentCount(1);

    CarbonImmutable::setTestNow('2026-11-03 05:41:01');
    $result = $this->daemon->tick();

    expect($result?->delivered_count)->toBe(2);
    Http::assertSentCount(2);
});

it('throws when the port is already in use', function (): void {
    expect(fn () => app(Daemon::class)->listen('127.0.0.1', $this->port))
        ->toThrow(RuntimeException::class, "Could not listen on tcp://127.0.0.1:{$this->port}");
});

it('receives and forwards what the app-side DaemonTransport sends', function (): void {
    Http::fake(['monitor.test/*' => Http::response(status: 202)]);
    config(['app-watch.daemon.host' => '127.0.0.1', 'app-watch.daemon.port' => $this->port, 'app-watch.daemon.flush_events' => 3]);
    $events = [requestEvent('trace-1'), queryEvent('trace-1'), logEvent('trace-1')];

    app(DaemonTransport::class)->send($events);
    tickDaemonUntil($this->daemon, fn (): bool => Http::recorded()->isNotEmpty());

    Http::assertSent(fn (Request $request): bool => ingestBatch($request)['events'] === array_map(fn ($event): array => $event->toArray(), $events));
});
