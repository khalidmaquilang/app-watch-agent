<?php

declare(strict_types=1);

use AppWatch\Agent\Storage\EventWriter;
use AppWatch\Agent\Storage\OutgoingRequestEventWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 06:00:00', 'UTC'));
    installTelemetrySchema();

    $this->writer = app(OutgoingRequestEventWriter::class);
});

afterEach(function (): void {
    dropTelemetrySchema();
});

it('stores the event with the given environment id and its column values', function (): void {
    $event = storageEvent('outgoing_request');

    $stored = $this->writer->write(7, [$event]);

    $row = DB::connection('app_watch')->table('outgoing_requests')->sole();

    expect($stored)->toBe(1)
        ->and($row->environment_id)->toBe(7)
        ->and($row->trace_id)->toBe($event['trace_id'])
        ->and(Carbon::parse($row->recorded_at)->utc()->format('Y-m-d\TH:i:s.uP'))->toBe($event['recorded_at'])
        ->and($row->host)->toBe($event['host'])
        ->and($row->deploy)->toBe($event['deploy'])
        ->and($row->method)->toBe('POST')
        ->and($row->target_host)->toBe('api.payments.example')
        ->and($row->url)->toBe('https://api.payments.example/v2/charges')
        ->and($row->status_code)->toBe(201)
        ->and($row->failed)->toBeFalse()
        ->and($row->duration_ms)->toBe(45.9);
});

it('stores nullable columns as null', function (): void {
    $this->writer->write(7, [storageEvent('outgoing_request', ['status_code' => null, 'failed' => true])]);

    $row = DB::connection('app_watch')->table('outgoing_requests')->sole();

    expect($row->environment_id)->toBe(7)
        ->and($row->status_code)->toBeNull()
        ->and($row->failed)->toBeTrue();
});

it('inserts in chunks of CHUNK_SIZE rows, one INSERT per chunk', function (): void {
    $counter = countTelemetryInserts('outgoing_requests');

    $stored = $this->writer->write(7, array_fill(0, EventWriter::CHUNK_SIZE + 1, storageEvent('outgoing_request')));

    expect($stored)->toBe(EventWriter::CHUNK_SIZE + 1)
        ->and($counter['inserts'])->toBe(2)
        ->and(DB::connection('app_watch')->table('outgoing_requests')->count())->toBe(EventWriter::CHUNK_SIZE + 1);
});

it('runs no query and returns 0 for an empty list', function (): void {
    $queries = 0;
    DB::connection('app_watch')->listen(function () use (&$queries): void {
        $queries++;
    });

    expect($this->writer->write(7, []))->toBe(0)
        ->and($queries)->toBe(0);
});
