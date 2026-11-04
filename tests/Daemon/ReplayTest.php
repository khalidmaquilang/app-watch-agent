<?php

declare(strict_types=1);

use AppWatch\Agent\Daemon\Outbox;
use AppWatch\Agent\Daemon\Spool;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    prepareTelemetryDatabase();

    $this->spool = app(Spool::class);
});

afterEach(function (): void {
    restoreTelemetryConnection();
    dropTelemetrySchema();
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});

/**
 * Three distinguishable batches of request events.
 *
 * @return array<int, array<int, array<string, mixed>>>
 */
function replayBatches(): array
{
    return [
        [[...fixtureEvent('request'), 'path' => '/a/1']],
        [[...fixtureEvent('request'), 'path' => '/b/1'], [...fixtureEvent('request'), 'path' => '/b/2']],
        [[...fixtureEvent('request'), 'path' => '/c/1']],
    ];
}

/**
 * The paths of the given batches, in order.
 *
 * @param  array<int, array<int, array<string, mixed>>>  $batches
 * @return array<int, string>
 */
function replayPaths(array $batches): array
{
    return array_column(array_merge(...$batches), 'path');
}

it('replays spooled batches oldest first, one per delivery, deleting each once written', function (): void {
    $batches = replayBatches();

    foreach ($batches as $batch) {
        $this->spool->write($batch);
    }

    $files = $this->spool->files();
    $outbox = app(Outbox::class);

    $first = $outbox->deliver();

    expect($first?->spool_file)->toBe($files[0])
        ->and($first?->stored_count)->toBe(1)
        ->and($this->spool->files())->toBe([$files[1], $files[2]]);

    $outbox->deliver();

    expect($this->spool->files())->toBe([$files[2]]);

    $outbox->deliver();

    expect($this->spool->files())->toBe([])
        ->and($outbox->deliver())->toBeNull()
        ->and(storedRequestPaths())->toBe(replayPaths($batches));
});

it('spools after three failed attempts while the database is down, then replays the spool and deletes the files once it is back', function (): void {
    breakTelemetryConnection();
    $outbox = app(Outbox::class);
    $batch = replayBatches()[1];
    $outbox->push($batch);

    for ($attempt = 1; $attempt <= Outbox::SPOOL_AFTER_ATTEMPTS; $attempt++) {
        expect($outbox->deliver()?->shouldRetry())->toBeTrue()
            ->and($outbox->nextAttemptAt()?->isFuture())->toBeTrue();

        CarbonImmutable::setTestNow($outbox->nextAttemptAt());
    }

    restoreTelemetryConnection();

    expect($outbox->pending())->toBe([])
        ->and(spooledBatches())->toBe([$batch])
        ->and(storedRequestPaths())->toBe([]);

    // While the app is idle, the oldest spooled batch is the retry.
    $result = $outbox->deliver();

    expect($result?->stored_count)->toBe(2)
        ->and($result?->spool_file)->not->toBeNull()
        ->and($this->spool->files())->toBe([])
        ->and(storedRequestPaths())->toBe(['/b/1', '/b/2']);
});

it('replays every batch spooled while the database was down, with the original payloads', function (): void {
    breakTelemetryConnection();
    $outbox = app(Outbox::class);
    $batches = replayBatches();

    foreach ($batches as $batch) {
        $outbox->push($batch);

        do {
            CarbonImmutable::setTestNow($outbox->nextAttemptAt() ?? CarbonImmutable::now());
            $outbox->deliver();
        } while ($outbox->pending() !== []);
    }

    expect(spooledBatches())->toBe($batches);

    restoreTelemetryConnection();
    CarbonImmutable::setTestNow($outbox->nextAttemptAt());

    for ($tick = 0; $tick < 5; $tick++) {
        $outbox->deliver();
    }

    expect($this->spool->files())->toBe([])
        ->and(storedRequestPaths())->toBe(replayPaths($batches));
});

it('writes live events before spooled ones, so live traffic is not starved', function (): void {
    $this->spool->write(replayBatches()[0]);
    $this->spool->write(replayBatches()[1]);
    $outbox = app(Outbox::class);

    $outbox->push([[...fixtureEvent('request'), 'path' => '/live/1']]);
    $outbox->deliver();
    $outbox->push([[...fixtureEvent('request'), 'path' => '/live/2']]);
    $outbox->deliver();
    $outbox->deliver();
    $outbox->deliver();

    expect(storedRequestPaths())->toBe(['/live/1', '/live/2', '/a/1', '/b/1', '/b/2'])
        ->and($this->spool->files())->toBe([]);
});

it('replays the files left by an earlier run on startup', function (): void {
    $batches = replayBatches();

    foreach ($batches as $batch) {
        (new Spool(config()))->write($batch);
    }

    $outbox = app(Outbox::class);

    for ($tick = 0; $tick < 4; $tick++) {
        $outbox->deliver();
    }

    expect(storedRequestPaths())->toBe(replayPaths($batches))
        ->and($this->spool->files())->toBe([]);
});

it('keeps a spooled batch and backs off when its replay fails', function (): void {
    breakTelemetryConnection();
    $this->spool->write(replayBatches()[0]);
    $file = $this->spool->files()[0];
    $outbox = app(Outbox::class);

    $result = $outbox->deliver();

    expect($result?->shouldRetry())->toBeTrue()
        ->and($result?->spool_file)->toBe($file)
        ->and($this->spool->files())->toBe([$file])
        ->and(spooledBatches())->toBe([replayBatches()[0]])
        ->and($outbox->isBackingOff())->toBeTrue()
        ->and($outbox->deliver())->toBeNull();

    restoreTelemetryConnection();
    CarbonImmutable::setTestNow('2026-11-03 05:41:01');

    expect($outbox->deliver()?->stored_count)->toBe(1)
        ->and($this->spool->files())->toBe([]);
});

it('writes back only the unwritten rest of a partly written spooled batch', function (): void {
    $events = daemonEvents(1500);
    $events[1200] = [...$events[1200], 'path' => '/deadlock'];
    DB::connection('app_watch')->unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION app_watch_test_fail_insert() RETURNS trigger AS $$
        BEGIN
            IF NEW.path = '/deadlock' THEN
                RAISE EXCEPTION 'deadlock' USING ERRCODE = '40P01';
            END IF;
            RETURN NEW;
        END
        $$ LANGUAGE plpgsql;
        CREATE TRIGGER app_watch_test_fail_insert BEFORE INSERT ON requests FOR EACH ROW EXECUTE FUNCTION app_watch_test_fail_insert();
        SQL);
    $this->spool->write($events);
    $file = $this->spool->files()[0];

    $result = app(Outbox::class)->deliver();

    expect($result?->stored_count)->toBe(1000)
        ->and($result?->shouldRetry())->toBeTrue()
        ->and($this->spool->files())->toBe([$file])
        ->and(spooledBatches())->toBe([array_slice($events, 1000)]);
});

it('deletes a spooled batch the database rejects as poison and goes on with the next', function (): void {
    $this->spool->write([[...fixtureEvent('request'), 'path' => '/poison']]);
    $this->spool->write(replayBatches()[1]);
    DB::connection('app_watch')->unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION app_watch_test_fail_insert() RETURNS trigger AS $$
        BEGIN
            IF NEW.path = '/poison' THEN
                RAISE EXCEPTION 'bad row' USING ERRCODE = '22P02';
            END IF;
            RETURN NEW;
        END
        $$ LANGUAGE plpgsql;
        CREATE TRIGGER app_watch_test_fail_insert BEFORE INSERT ON requests FOR EACH ROW EXECUTE FUNCTION app_watch_test_fail_insert();
        SQL);
    $outbox = app(Outbox::class);

    $poisoned = $outbox->deliver();

    expect($poisoned?->poison_count)->toBe(1)
        ->and($poisoned?->poison_sqlstate)->toBe('22P02')
        ->and($poisoned?->spool_file)->not->toBeNull()
        ->and(spooledBatches())->toBe([replayBatches()[1]])
        ->and($outbox->isBackingOff())->toBeFalse();

    $outbox->deliver();

    expect($this->spool->files())->toBe([])
        ->and(storedRequestPaths())->toBe(['/b/1', '/b/2']);
});

it('deletes a spooled batch whose recorded_at day has no partition', function (): void {
    DB::connection('app_watch')->statement('DROP TABLE requests_p20261104');
    $this->spool->write([[...fixtureEvent('request'), 'recorded_at' => '2026-11-04T10:00:00.000000+00:00']]);
    $outbox = app(Outbox::class);

    $result = $outbox->deliver();

    expect($result?->poison_count)->toBe(1)
        ->and($result?->isMissingPartition())->toBeTrue()
        ->and($this->spool->files())->toBe([])
        ->and($outbox->isBackingOff())->toBeFalse();
});

it('removes a corrupt spool file without blocking the replay of the others', function (): void {
    mkdir($this->spool_path, 0o755, true);
    file_put_contents($this->spool_path.'/00000000000000000001-corrupt.json.gz', 'truncated gzip');
    $this->spool->write(replayBatches()[0]);
    $this->spool->write(replayBatches()[1]);
    $outbox = app(Outbox::class);

    $outbox->deliver();
    $outbox->deliver();

    expect(storedRequestPaths())->toBe(['/a/1', '/b/1', '/b/2'])
        ->and($this->spool->files())->toBe([])
        ->and($outbox->spool()->corruptCount())->toBe(1);
});

it('does not scan an empty spool again until something is spooled', function (): void {
    $outbox = app(Outbox::class);

    expect($outbox->deliver())->toBeNull();

    // A file appearing behind the outbox's back is not picked up ...
    (new Spool(config()))->write(replayBatches()[0]);

    expect($outbox->deliver())->toBeNull();

    // ... until the outbox spools a batch itself.
    config(['app-watch.daemon.max_buffered_events' => 1]);
    $outbox->push(replayBatches()[1]);
    $outbox->deliver();
    $outbox->deliver();

    expect(storedRequestPaths())->toBe(['/a/1', '/b/1', '/b/2']);
});
