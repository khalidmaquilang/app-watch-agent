<?php

declare(strict_types=1);

use AppWatch\Agent\Storage\EventWriter;
use AppWatch\Agent\Storage\LogEventWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 06:00:00', 'UTC'));
    installTelemetrySchema();

    $this->writer = app(LogEventWriter::class);
});

afterEach(function (): void {
    dropTelemetrySchema();
});

it('stores the event with the given environment id and its column values', function (): void {
    $event = storageEvent('log');

    $stored = $this->writer->write(7, [$event]);

    $row = DB::connection('app_watch')->table('logs')->sole();

    expect($stored)->toBe(1)
        ->and($row->environment_id)->toBe(7)
        ->and($row->trace_id)->toBe($event['trace_id'])
        ->and(Carbon::parse($row->recorded_at)->utc()->format('Y-m-d\TH:i:s.uP'))->toBe($event['recorded_at'])
        ->and($row->host)->toBe($event['host'])
        ->and($row->deploy)->toBe($event['deploy'])
        ->and($row->level)->toBe('warning')
        ->and($row->message)->toBe('Payment provider responded slowly')
        ->and(json_decode($row->context, true))->toBe(['order_id' => 42, 'provider' => 'payments'])
        ->and(DB::connection('app_watch')->scalar('SELECT jsonb_typeof(context) FROM logs'))->toBe('object');
});

it('stores nullable columns as null', function (): void {
    $this->writer->write(7, [storageEvent('log', ['context' => null])]);

    $row = DB::connection('app_watch')->table('logs')->sole();

    expect($row->environment_id)->toBe(7)
        ->and($row->context)->toBeNull();
});

it('inserts in chunks of CHUNK_SIZE rows, one INSERT per chunk', function (): void {
    $counter = countTelemetryInserts('logs');

    $stored = $this->writer->write(7, array_fill(0, EventWriter::CHUNK_SIZE + 1, storageEvent('log')));

    expect($stored)->toBe(EventWriter::CHUNK_SIZE + 1)
        ->and($counter['inserts'])->toBe(2)
        ->and(DB::connection('app_watch')->table('logs')->count())->toBe(EventWriter::CHUNK_SIZE + 1);
});

it('runs no query and returns 0 for an empty list', function (): void {
    $queries = 0;
    DB::connection('app_watch')->listen(function () use (&$queries): void {
        $queries++;
    });

    expect($this->writer->write(7, []))->toBe(0)
        ->and($queries)->toBe(0);
});

it('replaces invalid UTF-8 in the context instead of failing', function (): void {
    $this->writer->write(7, [storageEvent('log', ['context' => ['bad' => "caf\xE9"]])]);

    expect(json_decode(DB::connection('app_watch')->table('logs')->sole()->context, true))->toBe(['bad' => "caf\u{FFFD}"]);
});
