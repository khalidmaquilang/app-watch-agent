<?php

declare(strict_types=1);

use AppWatch\Agent\Daemon\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    prepareTelemetryDatabase();

    $this->outbox = app(Outbox::class);
});

afterEach(function (): void {
    restoreTelemetryConnection();
    dropTelemetrySchema();
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});

it('retries a failed write with backoff and stores the events once', function (): void {
    breakTelemetryConnection();
    $events = daemonEvents(3);
    $this->outbox->push($events);

    $failed = $this->outbox->deliver();

    expect($failed?->shouldRetry())->toBeTrue()
        ->and($this->outbox->isBackingOff())->toBeTrue()
        ->and($this->outbox->nextAttemptAt()?->toDateTimeString())->toBe('2026-11-03 05:41:01')
        ->and($this->outbox->deliver())->toBeNull();

    restoreTelemetryConnection();
    CarbonImmutable::setTestNow('2026-11-03 05:41:01');
    $stored = $this->outbox->deliver();

    expect($stored?->stored_count)->toBe(3)
        ->and($this->outbox->pending())->toBe([])
        ->and($this->outbox->isBackingOff())->toBeFalse()
        ->and($this->outbox->deliver())->toBeNull()
        ->and(storedRequestPaths())->toBe(['/orders/1', '/orders/2', '/orders/3']);
});

it('drops a batch the database rejects as poison without retrying it', function (): void {
    failTelemetryInsertsWith('22P02');
    $this->outbox->push(daemonEvents(3));

    $result = $this->outbox->deliver();

    expect($result?->poison_count)->toBe(3)
        ->and($result?->poison_sqlstate)->toBe('22P02')
        ->and($result?->shouldRetry())->toBeFalse()
        ->and($this->outbox->pending())->toBe([])
        ->and($this->outbox->isBackingOff())->toBeFalse()
        ->and($this->outbox->deliver())->toBeNull()
        ->and(spooledBatches())->toBe([]);
});

it('retries a retryable SQLSTATE such as a deadlock', function (): void {
    failTelemetryInsertsWith('40P01');
    $events = daemonEvents(2);
    $this->outbox->push($events);

    $result = $this->outbox->deliver();

    expect($result?->shouldRetry())->toBeTrue()
        ->and($result?->poison_count)->toBe(0)
        ->and($this->outbox->pending())->toBe($events)
        ->and($this->outbox->isBackingOff())->toBeTrue();
});

it('doubles the backoff after every failure up to 60 seconds', function (): void {
    breakTelemetryConnection();
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

it('resets the backoff after a successful write', function (): void {
    breakTelemetryConnection();
    $this->outbox->push(daemonEvents(1));

    $this->outbox->deliver();
    CarbonImmutable::setTestNow('2026-11-03 05:41:01');
    $this->outbox->deliver();

    restoreTelemetryConnection();
    CarbonImmutable::setTestNow('2026-11-03 05:41:03');
    $this->outbox->deliver();

    breakTelemetryConnection();
    $this->outbox->push(daemonEvents(1));
    $this->outbox->deliver();

    expect($this->outbox->nextAttemptAt()?->toDateTimeString())->toBe('2026-11-03 05:41:04');
});

it('keeps events pushed while backing off and writes them with the retried ones', function (): void {
    breakTelemetryConnection();
    $first = daemonEvents(2);
    $second = [fixtureEvent('log')];

    $this->outbox->push($first);
    $this->outbox->deliver();
    $this->outbox->push($second);

    expect($this->outbox->pending())->toBe([...$first, ...$second]);

    restoreTelemetryConnection();
    CarbonImmutable::setTestNow('2026-11-03 05:41:01');

    expect($this->outbox->deliver()?->stored_count)->toBe(3)
        ->and(storedRequestPaths())->toBe(['/orders/1', '/orders/2'])
        ->and(DB::connection('app_watch')->table('logs')->count())->toBe(1);
});

it('spools every pending event instead of holding more than the memory cap', function (): void {
    config(['app-watch.daemon.max_buffered_events' => 5]);
    $events = daemonEvents(7);

    $this->outbox->push(array_slice($events, 0, 4));

    expect($this->outbox->pending())->toBe(array_slice($events, 0, 4));

    $this->outbox->push(array_slice($events, 4));

    expect($this->outbox->pending())->toBe([])
        ->and(spooledBatches())->toBe([$events])
        ->and($this->outbox->spooledCount())->toBe(7)
        ->and($this->outbox->droppedCount())->toBe(0);
});

it('spools the pending events after three failed writes in a row', function (): void {
    breakTelemetryConnection();
    $events = daemonEvents(3);
    $this->outbox->push($events);

    $this->outbox->deliver();
    CarbonImmutable::setTestNow($this->outbox->nextAttemptAt());
    $this->outbox->deliver();

    expect($this->outbox->pending())->toBe($events)
        ->and(spooledBatches())->toBe([]);

    CarbonImmutable::setTestNow($this->outbox->nextAttemptAt());
    $this->outbox->deliver();

    expect($this->outbox->pending())->toBe([])
        ->and(spooledBatches())->toBe([$events])
        ->and($this->outbox->isBackingOff())->toBeTrue()
        ->and($this->outbox->nextAttemptAt()?->isFuture())->toBeTrue();
});

it('drops and counts the events the spool cannot take', function (): void {
    $file = $this->spool_path.'-file';
    file_put_contents($file, 'not a directory');
    config(['app-watch.daemon.max_buffered_events' => 2, 'app-watch.daemon.spool_path' => $file.'/spool']);

    try {
        $this->outbox->push(daemonEvents(3));

        expect($this->outbox->pending())->toBe([])
            ->and($this->outbox->droppedCount())->toBe(3)
            ->and($this->outbox->spooledCount())->toBe(0)
            ->and($this->outbox->spool()->lastError())->toContain('is not writable');
    } finally {
        unlink($file);
    }
});

it('does nothing when nothing is pending', function (): void {
    expect($this->outbox->deliver())->toBeNull()
        ->and(telemetryRowCounts())->each->toBe(0);
});
