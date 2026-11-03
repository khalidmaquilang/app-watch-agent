<?php

declare(strict_types=1);

use AppWatch\Agent\Daemon\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    configureMonitor();
    CarbonImmutable::setTestNow('2026-11-03 05:41:00');
    Http::preventStrayRequests();

    $this->outbox = app(Outbox::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('retries a 503 with backoff and delivers the events once', function (): void {
    Http::fakeSequence('monitor.test/*')
        ->push(status: 503)
        ->push(['accepted' => 3], 202);
    $events = daemonEvents(3);
    $this->outbox->push($events);

    $failed = $this->outbox->deliver();

    expect($failed?->shouldRetry())->toBeTrue()
        ->and($this->outbox->isBackingOff())->toBeTrue()
        ->and($this->outbox->nextAttemptAt()?->toDateTimeString())->toBe('2026-11-03 05:41:01')
        ->and($this->outbox->deliver())->toBeNull();

    Http::assertSentCount(1);

    CarbonImmutable::setTestNow('2026-11-03 05:41:01');
    $delivered = $this->outbox->deliver();

    expect($delivered?->delivered_count)->toBe(3)
        ->and($this->outbox->pending())->toBe([])
        ->and($this->outbox->isBackingOff())->toBeFalse()
        ->and($this->outbox->deliver())->toBeNull();

    Http::assertSentCount(2);
    expect(Http::recorded()->map(fn (array $pair): array => ingestBatch($pair[0])['events'])->all())->toBe([$events, $events]);
});

it('drops a batch the monitor rejects with 401 without retrying it', function (): void {
    Http::fake(['monitor.test/*' => Http::response(status: 401)]);
    $this->outbox->push(daemonEvents(3));

    $result = $this->outbox->deliver();

    expect($result?->rejected_status)->toBe(401)
        ->and($result?->rejected_count)->toBe(3)
        ->and($this->outbox->pending())->toBe([])
        ->and($this->outbox->isBackingOff())->toBeFalse()
        ->and($this->outbox->deliver())->toBeNull();

    CarbonImmutable::setTestNow('2026-11-03 05:45:00');

    expect($this->outbox->deliver())->toBeNull();

    Http::assertSentCount(1);
});

it('doubles the backoff after every failure up to 60 seconds', function (): void {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));
    $this->outbox->push(daemonEvents(1));
    $waits = [];

    for ($attempt = 0; $attempt < 9; $attempt++) {
        $now = CarbonImmutable::now();
        $this->outbox->deliver();
        $next_attempt_at = $this->outbox->nextAttemptAt();
        $waits[] = (int) $now->diffInSeconds($next_attempt_at);
        CarbonImmutable::setTestNow($next_attempt_at);
    }

    expect($waits)->toBe([1, 2, 4, 8, 16, 32, 60, 60, 60]);
});

it('waits for the Retry-After the monitor sent', function (): void {
    Http::fake(['monitor.test/*' => Http::response(status: 429, headers: ['Retry-After' => '30'])]);
    $this->outbox->push(daemonEvents(1));

    $this->outbox->deliver();

    expect($this->outbox->nextAttemptAt()?->toDateTimeString())->toBe('2026-11-03 05:41:30');
});

it('resets the backoff after a successful delivery', function (): void {
    Http::fakeSequence('monitor.test/*')
        ->push(status: 503)
        ->push(status: 503)
        ->push(status: 202)
        ->push(status: 503);
    $this->outbox->push(daemonEvents(1));

    $this->outbox->deliver();
    CarbonImmutable::setTestNow('2026-11-03 05:41:01');
    $this->outbox->deliver();
    CarbonImmutable::setTestNow('2026-11-03 05:41:03');
    $this->outbox->deliver();

    $this->outbox->push(daemonEvents(1));
    $this->outbox->deliver();

    expect($this->outbox->nextAttemptAt()?->toDateTimeString())->toBe('2026-11-03 05:41:04');
});

it('keeps events pushed while backing off and sends them with the retried ones', function (): void {
    Http::fakeSequence('monitor.test/*')
        ->push(status: 503)
        ->push(status: 202);
    $first = daemonEvents(2);
    $second = [fixtureEvent('log')];

    $this->outbox->push($first);
    $this->outbox->deliver();
    $this->outbox->push($second);

    CarbonImmutable::setTestNow('2026-11-03 05:41:01');
    $this->outbox->deliver();

    expect(ingestBatch(Http::recorded()[1][0])['events'])->toBe([...$first, ...$second]);
});

it('drops and counts the oldest events past the memory cap', function (): void {
    config(['app-watch.daemon.max_buffered_events' => 5]);
    $events = daemonEvents(7);

    $this->outbox->push(array_slice($events, 0, 4));
    $this->outbox->push(array_slice($events, 4));

    expect($this->outbox->pending())->toBe(array_slice($events, 2))
        ->and($this->outbox->droppedCount())->toBe(2);
});

it('does nothing when nothing is pending', function (): void {
    Http::fake();

    expect($this->outbox->deliver())->toBeNull();

    Http::assertNothingSent();
});
