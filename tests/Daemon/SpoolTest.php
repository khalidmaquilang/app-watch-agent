<?php

declare(strict_types=1);

use AppWatch\Agent\Daemon\Outbox;
use AppWatch\Agent\Daemon\Spool;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    configureMonitor();
    CarbonImmutable::setTestNow('2026-11-03 05:41:00');
    Http::preventStrayRequests();

    $this->spool = app(Spool::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('writes a batch as a gzip JSON file named {timestamp}-{uuid}.json.gz and reads back the same events', function (): void {
    $events = [...daemonEvents(2), fixtureEvent('log'), fixtureEvent('query')];

    expect($this->spool->write($events))->toBeTrue();

    $files = $this->spool->files();

    expect($files)->toHaveCount(1)
        ->and(dirname($files[0]))->toBe($this->spool_path)
        ->and(basename($files[0]))->toMatch('/^\d{20}-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.json\.gz$/')
        ->and(spooledBatches())->toBe([$events])
        ->and($this->spool->oldest()?->events)->toBe($events)
        ->and($this->spool->oldest()?->path)->toBe($files[0]);
});

it('creates the spool directory when it does not exist', function (): void {
    config(['app-watch.daemon.spool_path' => $this->spool_path.'/nested/spool/']);

    expect($this->spool->write(daemonEvents(1)))->toBeTrue()
        ->and($this->spool->path())->toBe($this->spool_path.'/nested/spool')
        ->and($this->spool->files())->toHaveCount(1);
});

it('names batches oldest first, even when they are spooled within the same microsecond', function (): void {
    $batches = array_map(static fn (int $index): array => daemonEvents($index), range(1, 5));

    foreach ($batches as $batch) {
        $this->spool->write($batch);
    }

    expect(spooledBatches())->toBe($batches);

    // A new daemon process continues after the newest existing batch.
    $restarted_spool = new Spool(config());
    $restarted_spool->write(daemonEvents(6));

    expect(spooledBatches())->toBe([...$batches, daemonEvents(6)]);
});

it('deletes the oldest batches to stay under spool_max_bytes and counts their events', function (): void {
    $this->spool->write(daemonEvents(20));
    $batch_size = $this->spool->totalBytes();
    config(['app-watch.daemon.spool_max_bytes' => (int) ($batch_size * 3.5)]);

    for ($index = 1; $index <= 5; $index++) {
        $this->spool->write(daemonEvents(20));

        expect($this->spool->totalBytes())->toBeLessThanOrEqual((int) ($batch_size * 3.5));
    }

    expect($this->spool->files())->toHaveCount(3)
        ->and($this->spool->evictedCount())->toBe(60);
});

it('keeps the newest batches when it deletes the oldest', function (): void {
    $batches = array_map(
        static fn (int $batch): array => array_map(static fn (array $event): array => [...$event, 'path' => "/batch/{$batch}{$event['path']}"], daemonEvents(3)),
        [1, 2, 3],
    );
    $this->spool->write($batches[0]);
    config(['app-watch.daemon.spool_max_bytes' => $this->spool->totalBytes() * 2 + 20]);
    $this->spool->write($batches[1]);
    $this->spool->write($batches[2]);

    expect(spooledBatches())->toBe([$batches[1], $batches[2]])
        ->and($this->spool->evictedCount())->toBe(3);
});

it('refuses a batch larger than the whole spool without deleting anything', function (): void {
    $this->spool->write(daemonEvents(1));
    config(['app-watch.daemon.spool_max_bytes' => $this->spool->totalBytes() + 10]);

    expect($this->spool->write(daemonEvents(200)))->toBeFalse()
        ->and($this->spool->lastError())->toContain('larger than the spool limit')
        ->and(spooledBatches())->toBe([daemonEvents(1)])
        ->and($this->spool->evictedCount())->toBe(0);
});

it('returns false with the reason instead of throwing when the spool directory cannot be created', function (): void {
    $file = $this->spool_path.'-file';
    file_put_contents($file, 'not a directory');
    config(['app-watch.daemon.spool_path' => $file.'/spool']);

    try {
        expect($this->spool->write(daemonEvents(1)))->toBeFalse()
            ->and($this->spool->lastError())->toBe("the spool directory {$file}/spool is not writable")
            ->and($this->spool->files())->toBe([])
            ->and($this->spool->oldest())->toBeNull();
    } finally {
        unlink($file);
    }
});

it('deletes and counts corrupt files and returns the next readable batch', function (): void {
    mkdir($this->spool_path, 0o755, true);
    file_put_contents($this->spool_path.'/00000000000000000001-a.json.gz', 'not gzip');
    file_put_contents($this->spool_path.'/00000000000000000002-b.json.gz', gzencode('{"not": "a list"}'));
    file_put_contents($this->spool_path.'/00000000000000000003-c.json.gz', gzencode('[1, 2]'));
    $this->spool->write(daemonEvents(2));

    expect($this->spool->oldest()?->events)->toBe(daemonEvents(2))
        ->and($this->spool->corruptCount())->toBe(3)
        ->and($this->spool->files())->toHaveCount(1);
});

it('ignores temporary files left by an interrupted write', function (): void {
    mkdir($this->spool_path, 0o755, true);
    file_put_contents($this->spool_path.'/.00000000000000000001-a.json.gz.tmp', 'half');

    expect($this->spool->files())->toBe([])
        ->and($this->spool->oldest())->toBeNull();
});

it('replaces a batch in place, keeping its position', function (): void {
    $this->spool->write(daemonEvents(3));
    $this->spool->write(daemonEvents(1));
    $oldest = $this->spool->files()[0];

    expect($this->spool->replace($oldest, [fixtureEvent('log')]))->toBeTrue()
        ->and($this->spool->files()[0])->toBe($oldest)
        ->and(spooledBatches())->toBe([[fixtureEvent('log')], daemonEvents(1)]);
});

it('spools batches the monitor cannot be reached for, never exceeding spool_max_bytes', function (): void {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));
    $outbox = app(Outbox::class);
    $this->spool->write(daemonEvents(10));
    $max_bytes = (int) ($this->spool->totalBytes() * 2.5);
    $this->spool->delete($this->spool->files()[0]);
    config(['app-watch.daemon.spool_max_bytes' => $max_bytes]);
    $batches = [];

    for ($index = 1; $index <= 6; $index++) {
        $batch = array_map(static fn (array $event): array => [...$event, 'path' => "/batch/{$index}/{$event['path']}"], daemonEvents(10));
        $batches[] = $batch;
        $outbox->push($batch);

        // Three attempts per batch: the first attempt of a new batch already counts as the 4th failure in a row.
        do {
            CarbonImmutable::setTestNow($outbox->nextAttemptAt() ?? CarbonImmutable::now());
            $outbox->deliver();
        } while ($outbox->pending() !== []);

        expect($this->spool->totalBytes())->toBeLessThanOrEqual($max_bytes);
    }

    expect(spooledBatches())->toBe(array_slice($batches, -2))
        ->and($outbox->spool()->evictedCount())->toBe(40)
        ->and($outbox->spooledCount())->toBe(60)
        ->and($outbox->droppedCount())->toBe(0);
});
