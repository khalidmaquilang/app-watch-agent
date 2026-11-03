<?php

declare(strict_types=1);

use AppWatch\Agent\Daemon\Batcher;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-03 05:41:00');

    $this->batcher = app(Batcher::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('is due once 500 events are buffered', function (): void {
    foreach (daemonEvents(499) as $event) {
        $this->batcher->add($event);
    }

    expect($this->batcher->shouldFlush())->toBeFalse();

    $this->batcher->add(fixtureEvent('query'));

    expect($this->batcher->shouldFlush())->toBeTrue()
        ->and($this->batcher->flush())->toHaveCount(500)
        ->and($this->batcher->count())->toBe(0)
        ->and($this->batcher->shouldFlush())->toBeFalse();
});

it('is due 5 seconds after the oldest buffered event arrived', function (): void {
    foreach (daemonEvents(3) as $event) {
        $this->batcher->add($event);
    }

    CarbonImmutable::setTestNow('2026-11-03 05:41:04.999');

    expect($this->batcher->shouldFlush())->toBeFalse();

    CarbonImmutable::setTestNow('2026-11-03 05:41:05');

    expect($this->batcher->shouldFlush())->toBeTrue()
        ->and($this->batcher->flush())->toBe(daemonEvents(3));
});

it('never flushes an empty buffer, however long it waits', function (): void {
    CarbonImmutable::setTestNow('2026-11-03 06:00:00');

    expect($this->batcher->shouldFlush())->toBeFalse();
});

it('starts the interval again from the first event after a flush', function (): void {
    $this->batcher->add(fixtureEvent('request'));
    CarbonImmutable::setTestNow('2026-11-03 05:41:05');
    $this->batcher->flush();

    CarbonImmutable::setTestNow('2026-11-03 05:41:30');
    $this->batcher->add(fixtureEvent('request'));

    expect($this->batcher->shouldFlush())->toBeFalse();

    CarbonImmutable::setTestNow('2026-11-03 05:41:35');

    expect($this->batcher->shouldFlush())->toBeTrue();
});

it('uses the configured thresholds', function (): void {
    config(['app-watch.daemon.flush_events' => 2, 'app-watch.daemon.flush_seconds' => 1]);

    $this->batcher->add(fixtureEvent('request'));

    expect($this->batcher->shouldFlush())->toBeFalse();

    CarbonImmutable::setTestNow('2026-11-03 05:41:01');

    expect($this->batcher->shouldFlush())->toBeTrue();

    $this->batcher->flush();
    $this->batcher->add(fixtureEvent('request'));
    $this->batcher->add(fixtureEvent('request'));

    expect($this->batcher->shouldFlush())->toBeTrue();
});

it('falls back to the defaults for invalid thresholds', function (mixed $value, int $flush_events, int $flush_seconds): void {
    config(['app-watch.daemon.flush_events' => $value, 'app-watch.daemon.flush_seconds' => $value]);

    expect($this->batcher->flushEvents())->toBe($flush_events)
        ->and($this->batcher->flushSeconds())->toBe($flush_seconds);
})->with([
    'not a number' => ['many', Batcher::FLUSH_EVENTS, Batcher::FLUSH_SECONDS],
    'zero' => [0, 1, 1],
    'negative' => [-5, 1, 1],
]);
