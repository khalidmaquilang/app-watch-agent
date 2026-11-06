<?php

declare(strict_types=1);

use AppWatch\Agent\Storage\CommandEventWriter;
use AppWatch\Agent\Storage\EventWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 06:00:00', 'UTC'));
    installTelemetrySchema();

    $this->writer = app(CommandEventWriter::class);
});

afterEach(function (): void {
    dropTelemetrySchema();
});

it('stores the event with the given environment id and its column values', function (): void {
    $event = storageEvent('command');

    $stored = $this->writer->write(7, [$event]);

    $row = DB::connection('app_watch')->table('command_runs')->sole();

    expect($stored)->toBe(1)
        ->and($row->environment_id)->toBe(7)
        ->and($row->trace_id)->toBe($event['trace_id'])
        ->and(Carbon::parse($row->recorded_at)->utc()->format('Y-m-d\TH:i:s.uP'))->toBe($event['recorded_at'])
        ->and($row->host)->toBe($event['host'])
        ->and($row->deploy)->toBe($event['deploy'])
        ->and($row->command)->toBe('orders:sync')
        ->and(json_decode((string) $row->arguments, true))->toEqual(['email' => '?', 'force' => true])
        ->and($row->exit_code)->toBe(0)
        ->and($row->failed)->toBeFalse()
        ->and($row->duration_ms)->toBe(1520.75);
});

it('stores arguments as a jsonb object', function (): void {
    $this->writer->write(7, [storageEvent('command', ['arguments' => ['email' => '?']])]);

    expect(DB::connection('app_watch')->scalar('SELECT jsonb_typeof(arguments) FROM command_runs'))->toBe('object')
        ->and(DB::connection('app_watch')->scalar("SELECT arguments->>'email' FROM command_runs"))->toBe('?');
});

it('stores a failed run with its exit code', function (): void {
    $this->writer->write(7, [storageEvent('command', ['exit_code' => 255, 'failed' => true])]);

    $row = DB::connection('app_watch')->table('command_runs')->sole();

    expect($row->exit_code)->toBe(255)
        ->and($row->failed)->toBeTrue();
});

it('stores empty or missing arguments and a missing deploy as null', function (array $overrides): void {
    $this->writer->write(7, [storageEvent('command', $overrides)]);

    $row = DB::connection('app_watch')->table('command_runs')->sole();

    expect($row->arguments)->toBeNull()
        ->and($row->deploy)->toBeNull();
})->with([
    'empty arguments' => [['arguments' => [], 'deploy' => null]],
    'null arguments' => [['arguments' => null, 'deploy' => null]],
]);

it('inserts in chunks of CHUNK_SIZE rows, one INSERT per chunk', function (): void {
    $counter = countTelemetryInserts('command_runs');

    $stored = $this->writer->write(7, array_fill(0, EventWriter::CHUNK_SIZE + 1, storageEvent('command')));

    expect($stored)->toBe(EventWriter::CHUNK_SIZE + 1)
        ->and($counter['inserts'])->toBe(2)
        ->and(DB::connection('app_watch')->table('command_runs')->count())->toBe(EventWriter::CHUNK_SIZE + 1);
});

it('runs no query and returns 0 for an empty list', function (): void {
    $queries = 0;
    DB::connection('app_watch')->listen(function () use (&$queries): void {
        $queries++;
    });

    expect($this->writer->write(7, []))->toBe(0)
        ->and($queries)->toBe(0);
});
