<?php

declare(strict_types=1);

use AppWatch\Agent\Daemon\IngestClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    configureMonitor();
    CarbonImmutable::setTestNow('2026-11-03 05:41:05');
    Http::preventStrayRequests();

    $this->client = app(IngestClient::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('posts a gzipped v1 batch with the bearer token', function (): void {
    Http::fake(['monitor.test/*' => Http::response(['accepted' => 500], 202)]);
    $events = daemonEvents(500);

    $result = $this->client->send($events);

    expect($result->delivered_count)->toBe(500)
        ->and($result->rejected_count)->toBe(0)
        ->and($result->shouldRetry())->toBeFalse();

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) use ($events): bool {
        $batch = ingestBatch($request);
        $fixture = fixtureBatch();

        expect($request->url())->toBe('https://monitor.test/api/v1/ingest')
            ->and($request->method())->toBe('POST')
            ->and($request->header('Authorization'))->toBe(['Bearer aw_test_token'])
            ->and($request->header('Content-Encoding'))->toBe(['gzip'])
            ->and($request->header('Content-Type'))->toBe(['application/json'])
            ->and($request->header('Accept'))->toBe(['application/json'])
            ->and(array_keys($batch))->toBe(array_keys($fixture))
            ->and(array_keys($batch['environment']))->toBe(array_keys($fixture['environment']))
            ->and(array_map(get_debug_type(...), $batch['environment']))->toBe(array_map(get_debug_type(...), $fixture['environment']))
            ->and($batch['sent_at'])->toBe('2026-11-03T05:41:05.000000+00:00')
            ->and($batch['environment']['php_version'])->toBe(PHP_VERSION)
            ->and($batch['environment']['laravel_version'])->toBe(app()->version())
            ->and($batch['events'])->toBe($events);

        return true;
    });
});

it('sends the contract fixture events unchanged, floats included', function (): void {
    Http::fake(['monitor.test/*' => Http::response(status: 202)]);
    $events = fixtureBatch()['events'];

    $this->client->send($events);

    Http::assertSent(fn (Request $request): bool => ingestBatch($request)['events'] === $events);
});

it('builds the URL from the monitor URL and API version', function (string $monitor_url, string $api_version, string $url): void {
    config(['app-watch.monitor_url' => $monitor_url, 'app-watch.api_version' => $api_version]);

    expect($this->client->url())->toBe($url);
})->with([
    ['https://monitor.test', 'v1', 'https://monitor.test/api/v1/ingest'],
    ['https://monitor.test/', 'v2', 'https://monitor.test/api/v2/ingest'],
    ['https://monitor.test/apm', '', 'https://monitor.test/apm/api/v1/ingest'],
]);

it('splits more than 1000 events into batches of at most 1000', function (): void {
    Http::fake(['monitor.test/*' => Http::response(status: 202)]);
    $events = daemonEvents(1200);

    $result = $this->client->send($events);

    expect($result->delivered_count)->toBe(1200);

    $sizes = Http::recorded()->map(fn (array $pair): int => count(ingestBatch($pair[0])['events']))->all();

    expect($sizes)->toBe([1000, 200]);
    expect(array_merge(...Http::recorded()->map(fn (array $pair): array => ingestBatch($pair[0])['events'])->all()))->toBe($events);
});

it('hands the failed batch and every later one back for a retry on a retryable status', function (int $status): void {
    Http::fakeSequence('monitor.test/*')
        ->push(status: 202)
        ->push(status: $status);
    $events = daemonEvents(2500);

    $result = $this->client->send($events);

    expect($result->delivered_count)->toBe(1000)
        ->and($result->shouldRetry())->toBeTrue()
        ->and($result->pending)->toBe(array_slice($events, 1000))
        ->and($result->failure)->toBe("HTTP {$status}");

    Http::assertSentCount(2);
})->with([408, 429, 500, 502, 503]);

it('hands every event back for a retry on a connection error', function (): void {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));
    $events = daemonEvents(3);

    $result = $this->client->send($events);

    expect($result->delivered_count)->toBe(0)
        ->and($result->pending)->toBe($events)
        ->and($result->failure)->toBe('Connection refused')
        ->and($result->retry_after_seconds)->toBeNull();
});

it('reads Retry-After as seconds or an HTTP date, capped', function (string $retry_after, ?int $seconds): void {
    Http::fake(['monitor.test/*' => Http::response(status: 429, headers: ['Retry-After' => $retry_after])]);

    expect($this->client->send(daemonEvents(1))->retry_after_seconds)->toBe($seconds);
})->with([
    'seconds' => ['7', 7],
    'HTTP date' => ['Tue, 03 Nov 2026 05:41:35 GMT', 30],
    'a date in the past' => ['Tue, 03 Nov 2026 05:00:00 GMT', 0],
    'too long' => ['86400', IngestClient::MAX_RETRY_AFTER_SECONDS],
    'invalid' => ['soon', null],
]);

it('rejects a batch for good on a non-retryable status and keeps sending the others', function (int $status): void {
    Http::fakeSequence('monitor.test/*')
        ->push(status: $status)
        ->push(status: 202);

    $result = $this->client->send(daemonEvents(1500));

    expect($result->delivered_count)->toBe(500)
        ->and($result->rejected_count)->toBe(1000)
        ->and($result->rejected_status)->toBe($status)
        ->and($result->shouldRetry())->toBeFalse();

    Http::assertSentCount(2);
})->with([400, 401, 403, 413, 422]);

it('does not follow redirects', function (): void {
    Http::fake(['monitor.test/*' => Http::response(status: 302, headers: ['Location' => 'https://monitor.test/login'])]);

    $result = $this->client->send(daemonEvents(1));

    expect($result->rejected_status)->toBe(302);

    Http::assertSentCount(1);
});
