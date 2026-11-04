<?php

declare(strict_types=1);

use AppWatch\Agent\Storage\FingerprintException;
use AppWatch\Agent\Storage\UpsertExceptionGroup;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 06:00:00', 'UTC'));
    installTelemetrySchema();

    $this->upsert = app(UpsertExceptionGroup::class);
});

afterEach(function (): void {
    dropTelemetrySchema();
});

/**
 * Upsert the fixture exception's group with overrides, returning the group id.
 */
function upsertFixtureGroup(int $environment_id = 1, string $message = 'Mail transport unavailable', string $recorded_at = '2026-11-03T05:41:04.010550+00:00', int $line = 88, string $file = '/var/www/app/Mail/Transport.php', ?string $last_seen_at = null, int $count = 1): int
{
    return app(UpsertExceptionGroup::class)->upsert(
        $environment_id,
        'RuntimeException',
        $message,
        $file,
        $line,
        CarbonImmutable::parse($recorded_at),
        $last_seen_at === null ? null : CarbonImmutable::parse($last_seen_at),
        $count,
    );
}

/**
 * The only exception group row.
 */
function soleExceptionGroup(): stdClass
{
    return DB::connection('app_watch')->table('exception_groups')->sole();
}

/**
 * A timestamptz column value as ISO-8601 UTC with microseconds.
 */
function isoUtc(?string $value): ?string
{
    return $value === null ? null : Carbon::parse($value)->utc()->format('Y-m-d\TH:i:s.uP');
}

it('creates a group for a new fingerprint and returns its id', function (): void {
    $id = upsertFixtureGroup();

    $group = soleExceptionGroup();

    expect($id)->toBe($group->id)
        ->and($group->environment_id)->toBe(1)
        ->and($group->fingerprint)->toBe(app(FingerprintException::class)->fingerprint('RuntimeException', '/var/www/app/Mail/Transport.php', 88, 'Mail transport unavailable'))
        ->and($group->class)->toBe('RuntimeException')
        ->and($group->message)->toBe('Mail transport unavailable')
        ->and($group->file)->toBe('/var/www/app/Mail/Transport.php')
        ->and($group->line)->toBe(88)
        ->and(isoUtc($group->first_seen_at))->toBe('2026-11-03T05:41:04.010550+00:00')
        ->and(isoUtc($group->last_seen_at))->toBe('2026-11-03T05:41:04.010550+00:00')
        ->and($group->occurrences)->toBe(1)
        ->and($group->resolved_at)->toBeNull();
});

it('adds to the existing group of the same fingerprint and keeps the first-seen raw message', function (): void {
    $first_id = upsertFixtureGroup(message: 'Order 1 failed');
    $second_id = upsertFixtureGroup(message: 'Order 2 failed', recorded_at: '2026-11-03T05:50:00.000000+00:00');

    $group = soleExceptionGroup();

    expect($second_id)->toBe($first_id)
        ->and($group->occurrences)->toBe(2)
        ->and($group->message)->toBe('Order 1 failed')
        ->and(isoUtc($group->last_seen_at))->toBe('2026-11-03T05:50:00.000000+00:00');
});

it('adds the given count and last seen time in one upsert', function (): void {
    $upserts = 0;
    DB::connection('app_watch')->listen(function (QueryExecuted $query) use (&$upserts): void {
        if (str_contains($query->sql, 'INSERT INTO exception_groups')) {
            $upserts++;
        }
    });

    upsertFixtureGroup(last_seen_at: '2026-11-03T05:59:00.000000+00:00', count: 50);

    $group = soleExceptionGroup();

    expect($upserts)->toBe(1)
        ->and($group->occurrences)->toBe(50)
        ->and(isoUtc($group->first_seen_at))->toBe('2026-11-03T05:41:04.010550+00:00')
        ->and(isoUtc($group->last_seen_at))->toBe('2026-11-03T05:59:00.000000+00:00');
});

it('uses LEAST/GREATEST for first and last seen when events arrive out of order', function (): void {
    upsertFixtureGroup(message: 'Order 2 failed', recorded_at: '2026-11-03T05:00:00.000000+00:00');
    upsertFixtureGroup(message: 'Order 1 failed', recorded_at: '2026-11-03T04:00:00.000000+00:00');
    upsertFixtureGroup(message: 'Order 3 failed', recorded_at: '2026-11-03T04:30:00.000000+00:00');

    $group = soleExceptionGroup();

    expect($group->occurrences)->toBe(3)
        ->and(isoUtc($group->first_seen_at))->toBe('2026-11-03T04:00:00.000000+00:00')
        ->and(isoUtc($group->last_seen_at))->toBe('2026-11-03T05:00:00.000000+00:00')
        ->and($group->message)->toBe('Order 1 failed');
});

it('creates separate groups for a different line', function (): void {
    expect(upsertFixtureGroup(line: 11))->not->toBe(upsertFixtureGroup(line: 10))
        ->and(DB::connection('app_watch')->table('exception_groups')->count())->toBe(2);
});

it('creates separate groups for the same exception in two environments', function (): void {
    expect(upsertFixtureGroup(environment_id: 2))->not->toBe(upsertFixtureGroup(environment_id: 1))
        ->and(DB::connection('app_watch')->table('exception_groups')->where('environment_id', 1)->sole()->occurrences)->toBe(1)
        ->and(DB::connection('app_watch')->table('exception_groups')->where('environment_id', 2)->sole()->occurrences)->toBe(1);
});

it('re-opens a resolved group that receives an occurrence recorded after it was resolved', function (): void {
    upsertFixtureGroup(recorded_at: '2026-11-03T04:00:00.000000+00:00');
    DB::connection('app_watch')->table('exception_groups')->update(['resolved_at' => '2026-11-03 05:00:00+00']);

    upsertFixtureGroup(recorded_at: '2026-11-03T05:41:04.010550+00:00');

    expect(soleExceptionGroup()->resolved_at)->toBeNull();
});

it('keeps a group resolved when the occurrence was recorded before it was resolved', function (): void {
    upsertFixtureGroup(recorded_at: '2026-11-03T03:00:00.000000+00:00');
    DB::connection('app_watch')->table('exception_groups')->update(['resolved_at' => '2026-11-03 05:00:00+00']);

    upsertFixtureGroup(recorded_at: '2026-11-03T04:00:00.000000+00:00');

    expect(isoUtc(soleExceptionGroup()->resolved_at))->toBe('2026-11-03T05:00:00.000000+00:00');
});

it('cuts a file path longer than the group column for the group row only', function (): void {
    $file = '/'.str_repeat('a', 2000).'.php';

    upsertFixtureGroup(file: $file);

    $group = soleExceptionGroup();

    expect(mb_strlen($group->file))->toBe(UpsertExceptionGroup::FILE_MAX_LENGTH)
        ->and($group->fingerprint)->toBe(app(FingerprintException::class)->fingerprint('RuntimeException', $file, 88, 'Mail transport unavailable'));
});
