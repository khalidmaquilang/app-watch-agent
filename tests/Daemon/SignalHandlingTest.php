<?php

declare(strict_types=1);

use AppWatch\Agent\Daemon\Batcher;
use AppWatch\Agent\Daemon\Daemon;
use AppWatch\Agent\Daemon\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    prepareTelemetryDatabase();
    config(['app-watch.daemon.port' => unusedPort(), 'app-watch.daemon.flush_seconds' => 60]);

    $this->batcher = app(Batcher::class);
    app()->instance(Batcher::class, $this->batcher);
});

afterEach(function (): void {
    restoreTelemetryConnection();
    dropTelemetrySchema();
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});

it('makes one final delivery attempt on shutdown and spools the buffered events when it fails', function (): void {
    breakTelemetryConnection();
    $events = daemonEvents(10);

    foreach ($events as $event) {
        $this->batcher->add($event);
    }

    $daemon = app(Daemon::class);
    $daemon->listen('127.0.0.1', 0);

    $result = $daemon->shutdown();

    expect($result?->shouldRetry())->toBeTrue()
        ->and($daemon->address())->toBeNull()
        ->and($this->batcher->count())->toBe(0)
        ->and($daemon->outbox()->pending())->toBe([])
        ->and($result?->pending)->toBe($events)
        ->and(spooledBatches())->toBe([$events]);
});

it('uses the shutdown statement timeout for the final attempt', function (): void {
    $statements = [];
    DB::connection('app_watch')->listen(function (QueryExecuted $query) use (&$statements): void {
        if (str_starts_with($query->sql, 'SET LOCAL statement_timeout')) {
            $statements[] = $query->sql;
        }
    });

    foreach (daemonEvents(2) as $event) {
        $this->batcher->add($event);
    }

    app(Daemon::class)->shutdown();

    expect($statements)->toBe(['SET LOCAL statement_timeout = '.(Outbox::SHUTDOWN_TIMEOUT_SECONDS * 1000)]);
});

it('spools nothing when the final delivery attempt succeeds', function (): void {
    foreach (daemonEvents(10) as $event) {
        $this->batcher->add($event);
    }

    $daemon = app(Daemon::class);

    expect($daemon->shutdown()?->stored_count)->toBe(10)
        ->and(spooledBatches())->toBe([])
        ->and(storedRequestPaths())->toBe(array_column(daemonEvents(10), 'path'));
});

it('makes the final attempt even while a failed delivery is backing off', function (): void {
    breakTelemetryConnection();
    $daemon = app(Daemon::class);
    $daemon->outbox()->push(daemonEvents(2));
    $daemon->outbox()->deliver();

    expect($daemon->outbox()->isBackingOff())->toBeTrue();

    restoreTelemetryConnection();

    expect($daemon->shutdown()?->stored_count)->toBe(2)
        ->and(storedRequestPaths())->toBe(['/orders/1', '/orders/2']);
});

it('takes in what open connections already sent before the final attempt', function (): void {
    $daemon = app(Daemon::class);
    $daemon->listen('127.0.0.1', 0);
    $client = stream_socket_client("tcp://{$daemon->address()}", $error_code, $error_message, 1.0);
    $daemon->tick();

    // Still connected; the last line has no trailing newline yet.
    fwrite($client, json_encode(fixtureEvent('log'), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)."\n".json_encode(fixtureEvent('job'), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    fflush($client);
    usleep(50_000);

    expect($daemon->connectionCount())->toBe(1);

    $result = $daemon->shutdown();
    fclose($client);

    expect($result?->stored_count)->toBe(2)
        ->and($daemon->connectionCount())->toBe(0)
        ->and(telemetryRowCounts())->toMatchArray(['logs' => 1, 'job_runs' => 1]);
});

it('does nothing on shutdown when nothing is buffered', function (): void {
    expect(app(Daemon::class)->shutdown())->toBeNull()
        ->and(telemetryRowCounts())->each->toBe(0);
});

it('flushes, spools what could not be sent and exits 0 on a signal', function (int $signal): void {
    $outbox = app(Outbox::class);
    app()->instance(Outbox::class, $outbox);
    breakTelemetryConnection();
    $events = daemonEvents(10);

    foreach ($events as $event) {
        $this->batcher->add($event);
    }

    // A real signal, sent from outside while the daemon loop runs.
    exec(sprintf('(sleep 0.5; kill -%d %d) > /dev/null 2>&1 &', $signal, getmypid()));

    $this->artisan('app-watch:agent', ['--max-ticks' => 80])
        ->expectsOutputToContain("Received signal {$signal}; flushing buffered events and stopping.")
        ->expectsOutputToContain('Spooled 10 events to')
        ->assertExitCode(0);

    expect(spooledBatches())->toBe([$events]);
})->with([
    'SIGTERM' => [SIGTERM],
    'SIGINT' => [SIGINT],
])->skip(fn (): bool => ! function_exists('pcntl_signal') || ! function_exists('exec'), 'needs the pcntl extension and exec()');

it('restores the default signal handlers when it stops', function (): void {
    $this->artisan('app-watch:agent', ['--max-ticks' => 1])->assertExitCode(0);

    expect(pcntl_signal_get_handler(SIGTERM))->toBe(SIG_DFL)
        ->and(pcntl_signal_get_handler(SIGINT))->toBe(SIG_DFL);
})->skip(fn (): bool => ! function_exists('pcntl_signal'), 'needs the pcntl extension');
