<?php

declare(strict_types=1);

use AppWatch\Agent\Storage\EventWriter;
use AppWatch\Agent\Storage\RequestEventWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 06:00:00', 'UTC'));
    installTelemetrySchema();

    $this->writer = app(RequestEventWriter::class);
});

afterEach(function (): void {
    dropTelemetrySchema();
});

it('stores the event with the given environment id and its column values', function (): void {
    $event = storageEvent('request');

    $stored = $this->writer->write(7, [$event]);

    $row = DB::connection('app_watch')->table('requests')->sole();

    expect($stored)->toBe(1)
        ->and($row->environment_id)->toBe(7)
        ->and($row->trace_id)->toBe($event['trace_id'])
        ->and(Carbon::parse($row->recorded_at)->utc()->format('Y-m-d\TH:i:s.uP'))->toBe($event['recorded_at'])
        ->and($row->host)->toBe($event['host'])
        ->and($row->deploy)->toBe($event['deploy'])
        ->and($row->method)->toBe('GET')
        ->and($row->route)->toBe('orders/{order}')
        ->and($row->path)->toBe('/orders/42')
        ->and($row->status_code)->toBe(200)
        ->and($row->duration_ms)->toBe(87.42)
        ->and($row->memory_peak_bytes)->toBe(18874368);
});

it('stores nullable columns as null', function (): void {
    $this->writer->write(7, [storageEvent('request', ['route' => null, 'memory_peak_bytes' => null, 'deploy' => null])]);

    $row = DB::connection('app_watch')->table('requests')->sole();

    expect($row->environment_id)->toBe(7)
        ->and($row->route)->toBeNull()
        ->and($row->memory_peak_bytes)->toBeNull()
        ->and($row->deploy)->toBeNull();
});

it('inserts in chunks of CHUNK_SIZE rows, one INSERT per chunk', function (): void {
    $counter = countTelemetryInserts('requests');

    $stored = $this->writer->write(7, array_fill(0, EventWriter::CHUNK_SIZE + 1, storageEvent('request')));

    expect($stored)->toBe(EventWriter::CHUNK_SIZE + 1)
        ->and($counter['inserts'])->toBe(2)
        ->and(DB::connection('app_watch')->table('requests')->count())->toBe(EventWriter::CHUNK_SIZE + 1);
});

it('runs no query and returns 0 for an empty list', function (): void {
    $queries = 0;
    DB::connection('app_watch')->listen(function () use (&$queries): void {
        $queries++;
    });

    expect($this->writer->write(7, []))->toBe(0)
        ->and($queries)->toBe(0);
});
