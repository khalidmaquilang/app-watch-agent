<?php

declare(strict_types=1);

use AppWatch\Agent\Storage\EventWriter;
use AppWatch\Agent\Storage\ExceptionEventWriter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 06:00:00', 'UTC'));
    installTelemetrySchema();

    $this->writer = app(ExceptionEventWriter::class);
});

afterEach(function (): void {
    dropTelemetrySchema();
});

/**
 * Exception events built from the fixture, one per overrides array.
 *
 * @param  list<array<string, mixed>>  $overrides
 * @return list<array<string, mixed>>
 */
function exceptionStorageEvents(array $overrides): array
{
    return array_map(fn (array $override): array => storageEvent('exception', $override), $overrides);
}

it('stores the occurrence with the given environment id, its column values and its group id', function (): void {
    $event = storageEvent('exception');

    $stored = $this->writer->write(7, [$event]);

    $row = DB::connection('app_watch')->table('exception_occurrences')->sole();
    $group = DB::connection('app_watch')->table('exception_groups')->sole();

    expect($stored)->toBe(1)
        ->and($row->environment_id)->toBe(7)
        ->and($row->exception_group_id)->toBe($group->id)
        ->and($row->trace_id)->toBe($event['trace_id'])
        ->and(Carbon::parse($row->recorded_at)->utc()->format('Y-m-d\TH:i:s.uP'))->toBe($event['recorded_at'])
        ->and($row->host)->toBe($event['host'])
        ->and($row->deploy)->toBe($event['deploy'])
        ->and($row->class)->toBe('RuntimeException')
        ->and($row->message)->toBe('Mail transport unavailable')
        ->and($row->file)->toBe('/var/www/app/Mail/Transport.php')
        ->and($row->line)->toBe(88)
        ->and(json_decode($row->trace, true))->toEqual($event['trace'])
        ->and($row->handled)->toBeFalse()
        ->and(DB::connection('app_watch')->scalar('SELECT jsonb_typeof(trace) FROM exception_occurrences'))->toBe('array')
        ->and($group->environment_id)->toBe(7);
});

it('groups messages that only differ by ids into one group with both occurrences', function (): void {
    $this->writer->write(7, exceptionStorageEvents([['message' => 'Order 1 failed'], ['message' => 'Order 2 failed']]));

    $group = DB::connection('app_watch')->table('exception_groups')->sole();

    expect($group->occurrences)->toBe(2)
        ->and(DB::connection('app_watch')->table('exception_occurrences')->where('exception_group_id', $group->id)->orderBy('message')->pluck('message')->all())
        ->toBe(['Order 1 failed', 'Order 2 failed']);
});

it('creates separate groups for a different line and links each occurrence to its own group', function (): void {
    $this->writer->write(7, exceptionStorageEvents([['line' => 10], ['line' => 11]]));

    $groups = DB::connection('app_watch')->table('exception_groups')->pluck('id', 'line')->all();

    expect($groups)->toHaveCount(2)
        ->and(DB::connection('app_watch')->table('exception_occurrences')->pluck('exception_group_id', 'line')->all())->toEqual($groups);
});

it('upserts 50 identical exceptions with a single group upsert adding 50', function (): void {
    $this->writer->write(7, exceptionStorageEvents([[]]));

    $upserts = 0;
    DB::connection('app_watch')->listen(function (QueryExecuted $query) use (&$upserts): void {
        if (str_contains($query->sql, 'INSERT INTO exception_groups')) {
            $upserts++;
        }
    });

    $stored = $this->writer->write(7, exceptionStorageEvents(array_fill(0, 50, [])));

    expect($stored)->toBe(50)
        ->and($upserts)->toBe(1)
        ->and(DB::connection('app_watch')->table('exception_groups')->sole()->occurrences)->toBe(51)
        ->and(DB::connection('app_watch')->table('exception_occurrences')->count())->toBe(51);
});

it('sets first and last seen to the earliest and latest recorded_at of out-of-order events', function (): void {
    $this->writer->write(7, exceptionStorageEvents([
        ['recorded_at' => '2026-11-03T05:00:00.000000+00:00', 'message' => 'Order 2 failed'],
        ['recorded_at' => '2026-11-03T03:00:00.000000+00:00', 'message' => 'Order 1 failed'],
        ['recorded_at' => '2026-11-03T05:30:00.000000+00:00', 'message' => 'Order 3 failed'],
        ['recorded_at' => '2026-11-03T04:00:00.000000+00:00', 'message' => 'Order 4 failed'],
    ]));

    $group = DB::connection('app_watch')->table('exception_groups')->sole();

    expect($group->occurrences)->toBe(4)
        ->and(Carbon::parse($group->first_seen_at)->utc()->format('Y-m-d\TH:i:s.uP'))->toBe('2026-11-03T03:00:00.000000+00:00')
        ->and(Carbon::parse($group->last_seen_at)->utc()->format('Y-m-d\TH:i:s.uP'))->toBe('2026-11-03T05:30:00.000000+00:00')
        ->and($group->message)->toBe('Order 1 failed');
});

it('inserts occurrences in chunks of CHUNK_SIZE rows, one INSERT per chunk', function (): void {
    $counter = countTelemetryInserts('exception_occurrences');

    $stored = $this->writer->write(7, exceptionStorageEvents(array_fill(0, EventWriter::CHUNK_SIZE + 1, [])));

    expect($stored)->toBe(EventWriter::CHUNK_SIZE + 1)
        ->and($counter['inserts'])->toBe(2)
        ->and(DB::connection('app_watch')->table('exception_occurrences')->count())->toBe(EventWriter::CHUNK_SIZE + 1);
});

it('replaces invalid UTF-8 in the trace instead of failing', function (): void {
    $this->writer->write(7, exceptionStorageEvents([['trace' => [['file' => null, 'line' => null, 'function' => "caf\xE9", 'class' => null]]]]));

    expect(json_decode(DB::connection('app_watch')->table('exception_occurrences')->sole()->trace, true)[0]['function'])->toBe("caf\u{FFFD}");
});

it('runs no query and returns 0 for an empty list', function (): void {
    $queries = 0;
    DB::connection('app_watch')->listen(function () use (&$queries): void {
        $queries++;
    });

    expect($this->writer->write(7, []))->toBe(0)
        ->and($queries)->toBe(0);
});
