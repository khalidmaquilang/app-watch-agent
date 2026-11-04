<?php

declare(strict_types=1);

use AppWatch\Agent\Storage\CacheEventWriter;
use AppWatch\Agent\Storage\EventWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 06:00:00', 'UTC'));
    installTelemetrySchema();

    $this->writer = app(CacheEventWriter::class);
});

afterEach(function (): void {
    dropTelemetrySchema();
});

it('stores the event with the given environment id and its column values', function (): void {
    $event = storageEvent('cache');

    $stored = $this->writer->write(7, [$event]);

    $row = DB::connection('app_watch')->table('cache_events')->sole();

    expect($stored)->toBe(1)
        ->and($row->environment_id)->toBe(7)
        ->and($row->trace_id)->toBe($event['trace_id'])
        ->and(Carbon::parse($row->recorded_at)->utc()->format('Y-m-d\TH:i:s.uP'))->toBe($event['recorded_at'])
        ->and($row->host)->toBe($event['host'])
        ->and($row->deploy)->toBe($event['deploy'])
        ->and($row->store)->toBe('redis')
        ->and($row->key)->toBe('orders:42')
        ->and($row->operation)->toBe('miss')
        ->and($row->duration_ms)->toBe(0.31);
});

it('stores nullable columns as null', function (): void {
    $this->writer->write(7, [storageEvent('cache', ['duration_ms' => null])]);

    $row = DB::connection('app_watch')->table('cache_events')->sole();

    expect($row->environment_id)->toBe(7)
        ->and($row->duration_ms)->toBeNull();
});

it('inserts in chunks of CHUNK_SIZE rows, one INSERT per chunk', function (): void {
    $counter = countTelemetryInserts('cache_events');

    $stored = $this->writer->write(7, array_fill(0, EventWriter::CHUNK_SIZE + 1, storageEvent('cache')));

    expect($stored)->toBe(EventWriter::CHUNK_SIZE + 1)
        ->and($counter['inserts'])->toBe(2)
        ->and(DB::connection('app_watch')->table('cache_events')->count())->toBe(EventWriter::CHUNK_SIZE + 1);
});

it('runs no query and returns 0 for an empty list', function (): void {
    $queries = 0;
    DB::connection('app_watch')->listen(function () use (&$queries): void {
        $queries++;
    });

    expect($this->writer->write(7, []))->toBe(0)
        ->and($queries)->toBe(0);
});
