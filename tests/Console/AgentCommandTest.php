<?php

declare(strict_types=1);

use AppWatch\Agent\Daemon\Batcher;
use AppWatch\Agent\Daemon\Daemon;
use AppWatch\Agent\Daemon\Outbox;
use AppWatch\Agent\Daemon\Spool;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    configureMonitor();
    config(['app-watch.daemon.port' => unusedPort()]);
    Http::preventStrayRequests();
});

it('refuses to start without a token', function (?string $token): void {
    config(['app-watch.token' => $token]);

    $this->artisan('app-watch:agent', ['--max-ticks' => 1])
        ->expectsOutputToContain('APP_WATCH_TOKEN is not set')
        ->assertExitCode(1);
})->with([null, '']);

it('refuses to start without a monitor URL', function (?string $monitor_url): void {
    config(['app-watch.monitor_url' => $monitor_url]);

    $this->artisan('app-watch:agent', ['--max-ticks' => 1])
        ->expectsOutputToContain('APP_WATCH_URL is not set')
        ->assertExitCode(1);
})->with([null, '']);

it('exits 1 with a message when the port is in use', function (): void {
    [$server, $port] = startTestDaemon();
    config(['app-watch.daemon.port' => $port]);

    try {
        $this->artisan('app-watch:agent', ['--max-ticks' => 1])
            ->expectsOutputToContain("Could not listen on tcp://127.0.0.1:{$port}")
            ->assertExitCode(1);
    } finally {
        fclose($server);
    }
});

it('listens, runs the given number of ticks and stops', function (): void {
    $port = config('app-watch.daemon.port');

    $this->artisan('app-watch:agent', ['--max-ticks' => 2])
        ->expectsOutputToContain("App Watch agent listening on tcp://127.0.0.1:{$port}")
        ->assertExitCode(0);

    // The socket is closed again, so the port can be bound.
    $daemon = app(Daemon::class);
    $daemon->listen('127.0.0.1', $port);
    $daemon->close();
});

it('reports a rejection status once until a batch is delivered again', function (): void {
    $batcher = app(Batcher::class);
    $outbox = app(Outbox::class);
    app()->instance(Batcher::class, $batcher);
    app()->instance(Outbox::class, $outbox);
    config(['app-watch.daemon.flush_events' => 1]);
    $outbox->push(daemonEvents(5));
    $statuses = [401, 401, 202, 401];
    $calls = 0;

    // Each POST makes new events arrive, so every tick delivers a batch: 5 rejected, 1 rejected, 2 delivered, 3 rejected.
    Http::fake(function () use ($batcher, $statuses, &$calls) {
        $calls++;

        foreach (daemonEvents($calls) as $event) {
            $batcher->add($event);
        }

        return Http::response(status: $statuses[$calls - 1]);
    });

    $this->artisan('app-watch:agent', ['--max-ticks' => 4])
        ->expectsOutputToContain('The monitor rejected 5 events with HTTP 401')
        ->doesntExpectOutputToContain('The monitor rejected 1 events')
        ->expectsOutputToContain('The monitor rejected 3 events with HTTP 401')
        ->assertExitCode(0);

    expect($calls)->toBe(4);
});

it('reports a delivery that will be retried', function (): void {
    $outbox = app(Outbox::class);
    app()->instance(Outbox::class, $outbox);
    $outbox->push(daemonEvents(3));
    Http::fake(['monitor.test/*' => Http::response(status: 503)]);

    $this->artisan('app-watch:agent', ['--max-ticks' => 1])
        ->expectsOutputToContain('Could not deliver 3 events (HTTP 503); retrying at')
        ->assertExitCode(0);
});

it('reports events spooled from a full buffer', function (): void {
    config(['app-watch.daemon.max_buffered_events' => 1]);
    $outbox = app(Outbox::class);
    app()->instance(Outbox::class, $outbox);
    $outbox->push(daemonEvents(3));
    Http::fake(['monitor.test/*' => Http::response(status: 503)]);

    $this->artisan('app-watch:agent', ['--max-ticks' => 1])
        ->expectsOutputToContain("Spooled 3 events to {$this->spool_path}")
        ->assertExitCode(0);
});

it('reports events dropped because the spool cannot take them', function (): void {
    $file = $this->spool_path.'-file';
    file_put_contents($file, 'not a directory');
    config(['app-watch.daemon.max_buffered_events' => 1, 'app-watch.daemon.spool_path' => $file.'/spool']);
    $outbox = app(Outbox::class);
    app()->instance(Outbox::class, $outbox);
    $outbox->push(daemonEvents(3));
    Http::fake();

    try {
        $this->artisan('app-watch:agent', ['--max-ticks' => 1])
            ->expectsOutputToContain("Dropped 3 events: they could not be spooled (the spool directory {$file}/spool is not writable).")
            ->assertExitCode(0);
    } finally {
        unlink($file);
    }
});

it('reports a spooled batch deleted as poison after a 422', function (): void {
    app(Spool::class)->write(daemonEvents(4));
    $file = basename(app(Spool::class)->files()[0]);
    Http::fake(['monitor.test/*' => Http::response(['message' => 'invalid'], 422)]);

    $this->artisan('app-watch:agent', ['--max-ticks' => 1])
        ->expectsOutputToContain("Deleted spooled batch {$file}: the monitor rejected its 4 events with HTTP 422.")
        ->assertExitCode(0);

    expect(app(Spool::class)->files())->toBe([]);
});

it('reports corrupt spool files and spooled events deleted for the size limit', function (): void {
    mkdir($this->spool_path, 0o755, true);
    file_put_contents($this->spool_path.'/00000000000000000001-corrupt.json.gz', 'not gzip');
    app(Spool::class)->write(daemonEvents(5));
    config(['app-watch.daemon.max_buffered_events' => 1, 'app-watch.daemon.spool_max_bytes' => filesize(app(Spool::class)->files()[1]) + 10]);
    $outbox = app(Outbox::class);
    app()->instance(Outbox::class, $outbox);
    $outbox->push(daemonEvents(5));
    Http::fake(['monitor.test/*' => Http::response(status: 503)]);

    $this->artisan('app-watch:agent', ['--max-ticks' => 1])
        ->expectsOutputToContain('Deleted 1 corrupt spool files.')
        ->expectsOutputToContain('Dropped 5 spooled events: the spool reached its size limit')
        ->assertExitCode(0);
});
