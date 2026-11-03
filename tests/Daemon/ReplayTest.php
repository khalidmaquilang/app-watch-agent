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

/**
 * Three distinguishable batches of events.
 *
 * @return array<int, array<int, array<string, mixed>>>
 */
function replayBatches(): array
{
    return [daemonEvents(1), daemonEvents(2), [fixtureEvent('log'), fixtureEvent('query')]];
}

/**
 * The events of every ingest request sent so far, one array per request.
 *
 * @return array<int, array<int, array<string, mixed>>>
 */
function postedBatches(): array
{
    return Http::recorded()->map(fn (array $pair): array => ingestBatch($pair[0])['events'])->values()->all();
}

it('replays spooled batches oldest first, one per delivery, deleting each after a 2xx', function (): void {
    Http::fake(['monitor.test/*' => Http::response(['accepted' => 1], 202)]);
    $batches = replayBatches();

    foreach ($batches as $batch) {
        $this->spool->write($batch);
    }

    $files = $this->spool->files();
    $outbox = app(Outbox::class);

    $first = $outbox->deliver();

    expect($first?->spool_file)->toBe($files[0])
        ->and($first?->delivered_count)->toBe(1)
        ->and($this->spool->files())->toBe([$files[1], $files[2]]);

    $outbox->deliver();

    expect($this->spool->files())->toBe([$files[2]]);

    $outbox->deliver();

    expect($this->spool->files())->toBe([])
        ->and($outbox->deliver())->toBeNull()
        ->and(postedBatches())->toBe($batches);
});

it('replays the spool once the monitor is reachable again, with the original payloads', function (): void {
    $reachable = false;
    Http::fake(function () use (&$reachable) {
        if (! $reachable) {
            throw new ConnectionException('Connection refused');
        }

        return Http::response(['accepted' => 1], 202);
    });
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

    $failed_attempts = Http::recorded()->count();
    $reachable = true;
    CarbonImmutable::setTestNow($outbox->nextAttemptAt());

    // While the app is idle, the oldest spooled batch is the retry.
    for ($tick = 0; $tick < 5; $tick++) {
        $outbox->deliver();
    }

    expect($this->spool->files())->toBe([])
        ->and(array_slice(postedBatches(), $failed_attempts))->toBe($batches);
});

it('delivers live events before spooled ones, so live traffic is not starved', function (): void {
    Http::fake(['monitor.test/*' => Http::response(status: 202)]);
    $this->spool->write(replayBatches()[0]);
    $this->spool->write(replayBatches()[1]);
    $outbox = app(Outbox::class);
    $live = [fixtureEvent('job')];

    $outbox->push($live);
    $outbox->deliver();
    $outbox->push($live);
    $outbox->deliver();
    $outbox->deliver();
    $outbox->deliver();

    expect(postedBatches())->toBe([$live, $live, replayBatches()[0], replayBatches()[1]])
        ->and($this->spool->files())->toBe([]);
});

it('replays the files left by an earlier run on startup', function (): void {
    Http::fake(['monitor.test/*' => Http::response(status: 202)]);
    $batches = replayBatches();

    foreach ($batches as $batch) {
        (new Spool(config()))->write($batch);
    }

    $outbox = app(Outbox::class);

    for ($tick = 0; $tick < 4; $tick++) {
        $outbox->deliver();
    }

    expect(postedBatches())->toBe($batches)
        ->and($this->spool->files())->toBe([]);
});

it('keeps a spooled batch and backs off when its replay fails', function (): void {
    Http::fakeSequence('monitor.test/*')
        ->push(status: 503)
        ->push(status: 202);
    $this->spool->write(replayBatches()[0]);
    $file = $this->spool->files()[0];
    $outbox = app(Outbox::class);

    $result = $outbox->deliver();

    expect($result?->shouldRetry())->toBeTrue()
        ->and($result?->spool_file)->toBe($file)
        ->and($this->spool->files())->toBe([$file])
        ->and($outbox->isBackingOff())->toBeTrue()
        ->and($outbox->deliver())->toBeNull();

    CarbonImmutable::setTestNow('2026-11-03 05:41:01');

    expect($outbox->deliver()?->delivered_count)->toBe(1)
        ->and($this->spool->files())->toBe([]);
});

it('writes back only the undelivered rest of a partly delivered spooled batch', function (): void {
    Http::fakeSequence('monitor.test/*')
        ->push(status: 202)
        ->push(status: 503);
    $events = daemonEvents(1500);
    $this->spool->write($events);
    $file = $this->spool->files()[0];

    $result = app(Outbox::class)->deliver();

    expect($result?->delivered_count)->toBe(1000)
        ->and($this->spool->files())->toBe([$file])
        ->and(spooledBatches())->toBe([array_slice($events, 1000)]);
});

it('deletes a spooled batch the monitor rejects with 422 and goes on with the next', function (): void {
    Http::fakeSequence('monitor.test/*')
        ->push(['message' => 'invalid'], 422)
        ->push(status: 202);
    $this->spool->write(replayBatches()[0]);
    $this->spool->write(replayBatches()[1]);
    $outbox = app(Outbox::class);

    $poisoned = $outbox->deliver();

    expect($poisoned?->rejected_status)->toBe(422)
        ->and($poisoned?->spool_file)->not->toBeNull()
        ->and(spooledBatches())->toBe([replayBatches()[1]])
        ->and($outbox->isBackingOff())->toBeFalse();

    $outbox->deliver();

    expect($this->spool->files())->toBe([]);
});

it('keeps spooled batches and pauses replay when the monitor rejects one with 401, until a live delivery succeeds', function (): void {
    Http::fakeSequence('monitor.test/*')
        ->push(status: 401)
        ->push(status: 202)
        ->push(status: 202);
    $this->spool->write(replayBatches()[0]);
    $outbox = app(Outbox::class);

    expect($outbox->deliver()?->rejected_status)->toBe(401)
        ->and($outbox->deliver())->toBeNull()
        ->and(spooledBatches())->toBe([replayBatches()[0]]);

    $outbox->push([fixtureEvent('job')]);
    $outbox->deliver();
    $outbox->deliver();

    expect($this->spool->files())->toBe([]);
    Http::assertSentCount(3);
});

it('removes a corrupt spool file without blocking the replay of the others', function (): void {
    Http::fake(['monitor.test/*' => Http::response(status: 202)]);
    mkdir($this->spool_path, 0o755, true);
    file_put_contents($this->spool_path.'/00000000000000000001-corrupt.json.gz', 'truncated gzip');
    $this->spool->write(replayBatches()[0]);
    $this->spool->write(replayBatches()[1]);
    $outbox = app(Outbox::class);

    $outbox->deliver();
    $outbox->deliver();

    expect(postedBatches())->toBe([replayBatches()[0], replayBatches()[1]])
        ->and($this->spool->files())->toBe([])
        ->and($outbox->spool()->corruptCount())->toBe(1);
});

it('does not scan an empty spool again until something is spooled', function (): void {
    Http::fake(['monitor.test/*' => Http::response(status: 202)]);
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

    expect(postedBatches())->toBe([replayBatches()[0], replayBatches()[1]]);
});
