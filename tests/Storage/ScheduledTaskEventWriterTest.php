<?php

declare(strict_types=1);

use AppWatch\Agent\Storage\EventWriter;
use AppWatch\Agent\Storage\ScheduledTaskEventWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 06:00:00', 'UTC'));
    installTelemetrySchema();

    $this->writer = app(ScheduledTaskEventWriter::class);
});

afterEach(function (): void {
    dropTelemetrySchema();
});

it('stores the event with the given environment id and its column values', function (): void {
    $event = storageEvent('scheduled_task');

    $stored = $this->writer->write(7, [$event]);

    $row = DB::connection('app_watch')->table('scheduled_task_runs')->sole();

    expect($stored)->toBe(1)
        ->and($row->environment_id)->toBe(7)
        ->and($row->trace_id)->toBe($event['trace_id'])
        ->and(Carbon::parse($row->recorded_at)->utc()->format('Y-m-d\TH:i:s.uP'))->toBe($event['recorded_at'])
        ->and($row->host)->toBe($event['host'])
        ->and($row->deploy)->toBe($event['deploy'])
        ->and($row->task)->toBe('emails:send ? --queue=?')
        ->and($row->kind)->toBe('command')
        ->and($row->expression)->toBe('*/5 * * * *')
        ->and($row->timezone)->toBe('Asia/Manila')
        ->and($row->outcome)->toBe('succeeded')
        ->and($row->exit_code)->toBe(0)
        ->and($row->duration_ms)->toBe(842.5)
        ->and($row->without_overlapping)->toBeTrue()
        ->and($row->on_one_server)->toBeFalse();
});

it('stores a skipped run with a zero duration and a closure or job run without an exit code or timezone', function (string $kind, string $outcome, float $duration_ms): void {
    $this->writer->write(7, [storageEvent('scheduled_task', [
        'kind' => $kind, 'outcome' => $outcome, 'duration_ms' => $duration_ms, 'exit_code' => null, 'timezone' => null,
    ])]);

    $row = DB::connection('app_watch')->table('scheduled_task_runs')->sole();

    expect($row->kind)->toBe($kind)
        ->and($row->outcome)->toBe($outcome)
        ->and($row->duration_ms)->toBe($duration_ms)
        ->and($row->exit_code)->toBeNull()
        ->and($row->timezone)->toBeNull();
})->with([
    'skipped closure' => ['closure', 'skipped', 0.0],
    'failed job' => ['job', 'failed', 35.25],
]);

it('stores the overlap and one-server flags as sent', function (): void {
    $this->writer->write(7, [storageEvent('scheduled_task', ['without_overlapping' => false, 'on_one_server' => true])]);

    $row = DB::connection('app_watch')->table('scheduled_task_runs')->sole();

    expect($row->without_overlapping)->toBeFalse()
        ->and($row->on_one_server)->toBeTrue();
});

it('inserts in chunks of CHUNK_SIZE rows, one INSERT per chunk', function (): void {
    $counter = countTelemetryInserts('scheduled_task_runs');

    $stored = $this->writer->write(7, array_fill(0, EventWriter::CHUNK_SIZE + 1, storageEvent('scheduled_task')));

    expect($stored)->toBe(EventWriter::CHUNK_SIZE + 1)
        ->and($counter['inserts'])->toBe(2)
        ->and(DB::connection('app_watch')->table('scheduled_task_runs')->count())->toBe(EventWriter::CHUNK_SIZE + 1);
});

it('runs no query and returns 0 for an empty list', function (): void {
    $queries = 0;
    DB::connection('app_watch')->listen(function () use (&$queries): void {
        $queries++;
    });

    expect($this->writer->write(7, []))->toBe(0)
        ->and($queries)->toBe(0);
});
