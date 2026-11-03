<?php

declare(strict_types=1);

use AppWatch\Agent\Enums\LogLevelEnum;
use AppWatch\Agent\Payloads\EventMetadata;
use AppWatch\Agent\Payloads\LogEventPayload;
use AppWatch\Agent\Payloads\QueryEventPayload;
use AppWatch\Agent\Transport\DaemonTransport;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    [$this->server, $port] = startTestDaemon();

    config(['app-watch.daemon.host' => '127.0.0.1', 'app-watch.daemon.port' => $port]);

    $this->transport = app(DaemonTransport::class);
});

afterEach(function (): void {
    if (is_resource($this->server)) {
        fclose($this->server);
    }
});

it('writes one JSON line per event in the v1 event shape over one connection', function (): void {
    $trace_id = '0193b1f0-0000-7000-8000-000000000001';
    $events = [requestEvent($trace_id), queryEvent($trace_id), logEvent($trace_id)];

    $this->transport->send($events);

    $connections = readTestDaemon($this->server);

    expect($connections)->toHaveCount(1)
        ->and($connections[0])->toHaveCount(3)
        ->and($this->transport->droppedCount())->toBe(0);

    foreach ($connections[0] as $index => $line) {
        expect($line)->toBe($events[$index]->toArray())
            ->and(array_keys($line))->toBe(array_keys(fixtureEvent($line['type'])))
            ->and($line['trace_id'])->toBe($trace_id);
    }
});

it('keeps float fields as floats', function (): void {
    $this->transport->send([requestEvent('trace-1')]);

    expect(readTestDaemon($this->server)[0][0]['duration_ms'])->toBe(10.0);
});

it('does not connect for an empty batch', function (): void {
    $this->transport->send([]);

    expect(readTestDaemon($this->server))->toBe([]);
});

it('substitutes invalid UTF-8 instead of dropping the event or the batch', function (): void {
    $events = [
        requestEvent('trace-1'),
        new LogEventPayload(EventMetadata::capture('trace-1'), LogLevelEnum::Error, "Bad byte \xB1 here", ['note' => "\xC3\x28"]),
        queryEvent('trace-1'),
    ];

    $this->transport->send($events);

    $lines = readTestDaemon($this->server)[0];

    expect($lines)->toHaveCount(3)
        ->and($lines[1]['message'])->toBe("Bad byte \u{FFFD} here")
        ->and($lines[1]['context'])->toBe(['note' => "\u{FFFD}("])
        ->and($this->transport->droppedCount())->toBe(0);
});

it('skips and counts an event that cannot be encoded but sends the others', function (): void {
    $unencodable = new QueryEventPayload(EventMetadata::capture('trace-1'), 'sqlite', 'select 1', sha1('select 1'), NAN);

    $this->transport->send([requestEvent('trace-1'), $unencodable, logEvent('trace-1')]);

    $lines = readTestDaemon($this->server)[0];

    expect(array_column($lines, 'type'))->toBe(['request', 'log'])
        ->and($this->transport->droppedCount())->toBe(1);
});

it('swallows a refused connection within the connect timeout and counts the batch as dropped', function (): void {
    config(['app-watch.daemon.port' => unusedPort()]);

    $started_at = hrtime(true);
    $this->transport->send([requestEvent('trace-1'), queryEvent('trace-1')]);
    $elapsed_ms = (hrtime(true) - $started_at) / 1_000_000;

    expect($elapsed_ms)->toBeLessThan(100.0)
        ->and($this->transport->droppedCount())->toBe(2);
});

it('swallows an invalid daemon address', function (mixed $host, mixed $port): void {
    config(['app-watch.daemon.host' => $host, 'app-watch.daemon.port' => $port]);

    $this->transport->send([requestEvent('trace-1')]);

    expect($this->transport->droppedCount())->toBe(1);
})->with([
    'non-string host' => [['127.0.0.1'], 2407],
    'non-numeric port' => ['127.0.0.1', 'not-a-port'],
    'unresolvable host' => ['app-watch-daemon.invalid', 2407],
]);

it('does not raise PHP warnings or log through the app when sending fails', function (): void {
    config(['app-watch.daemon.port' => unusedPort()]);
    $logged = [];
    Event::listen(MessageLogged::class, function ($event) use (&$logged): void {
        $logged[] = $event->message;
    });

    $this->transport->send([requestEvent('trace-1')]);

    expect($logged)->toBe([])
        ->and(error_get_last())->toBeNull();
});

it('writes a large batch completely across partial writes', function (): void {
    $server = $this->server;
    $output_file = tempnam(sys_get_temp_dir(), 'app-watch-daemon-');
    $message = str_repeat('x', 2000);
    $events = array_map(
        fn (int $index): LogEventPayload => new LogEventPayload(EventMetadata::capture('trace-1'), LogLevelEnum::Error, "{$index} {$message}", null),
        range(1, 2000),
    );

    $pid = pcntl_fork();

    if ($pid === 0) {
        // The child plays the daemon: it reads everything the parent writes, then dies without
        // running the parent's shutdown handlers.
        $connection = stream_socket_accept($server, 5);
        file_put_contents($output_file, $connection === false ? '' : (string) stream_get_contents($connection));
        posix_kill(getmypid(), SIGKILL);
    }

    $this->transport->send($events);
    pcntl_waitpid($pid, $status);

    $lines = array_values(array_filter(explode("\n", (string) file_get_contents($output_file))));
    unlink($output_file);

    expect(strlen(implode("\n", $lines)))->toBeGreaterThan(DaemonTransport::WRITE_CHUNK_BYTES * 10)
        ->and($lines)->toHaveCount(2000)
        ->and(json_decode($lines[1999], true)['message'])->toStartWith('2000 ')
        ->and($this->transport->droppedCount())->toBe(0);
})->skip(! function_exists('pcntl_fork') || ! function_exists('posix_kill'), 'Needs the pcntl and posix extensions.');
