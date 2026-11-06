<?php

declare(strict_types=1);

use AppWatch\Agent\Maintenance\CreatePartitions;
use AppWatch\Agent\Storage\BatchWriter;
use AppWatch\Agent\Storage\CacheEventWriter;
use AppWatch\Agent\Storage\CommandEventWriter;
use AppWatch\Agent\Storage\EventValidator;
use AppWatch\Agent\Storage\ExceptionEventWriter;
use AppWatch\Agent\Storage\FingerprintException;
use AppWatch\Agent\Storage\JobEventWriter;
use AppWatch\Agent\Storage\LogEventWriter;
use AppWatch\Agent\Storage\NormalizeExceptionMessage;
use AppWatch\Agent\Storage\OutgoingRequestEventWriter;
use AppWatch\Agent\Storage\QueryEventWriter;
use AppWatch\Agent\Storage\RequestEventWriter;
use AppWatch\Agent\Storage\UpsertExceptionGroup;
use AppWatch\Agent\Storage\WriteStats;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-11-03 06:00:00', 'UTC'));
    config(['app-watch.retention_days' => 14, 'app-watch.partitions_ahead_days' => 7]);
    installTelemetrySchema();

    $this->writer = app(BatchWriter::class);
});

afterEach(function (): void {
    dropTelemetrySchema();
});

/**
 * Every table empty.
 *
 * @return array<string, int>
 */
function emptyTelemetryCounts(): array
{
    return array_map(static fn (): int => 0, telemetryRowCounts());
}

it('stores every fixture event in its table in one transaction and returns matching stats', function (): void {
    $transactions = 0;
    $insert_levels = [];
    Event::listen(TransactionBeginning::class, function (TransactionBeginning $event) use (&$transactions): void {
        if ($event->connectionName === 'app_watch') {
            $transactions++;
        }
    });
    DB::connection('app_watch')->listen(function (QueryExecuted $query) use (&$insert_levels): void {
        if (stripos($query->sql, 'insert into') === 0) {
            $insert_levels[] = $query->connection->transactionLevel();
        }
    });

    $stats = $this->writer->write(fixtureBatch()['events']);

    expect($stats)->toBeInstanceOf(WriteStats::class)
        ->and($stats->stored)->toBe([
            'request' => 1, 'query' => 1, 'job' => 1, 'outgoing_request' => 1, 'cache' => 1, 'log' => 1, 'exception' => 1,
            'command' => 1,
        ])
        ->and($stats->storedCount())->toBe(8)
        ->and($stats->skipped_retention)->toBe(0)
        ->and($stats->malformed)->toBe(0)
        ->and(telemetryRowCounts())->toBe([
            'requests' => 1, 'queries' => 1, 'job_runs' => 1, 'outgoing_requests' => 1, 'cache_events' => 1, 'logs' => 1,
            'exception_occurrences' => 1, 'command_runs' => 1, 'exception_groups' => 1,
        ])
        ->and($transactions)->toBe(1)
        ->and($insert_levels)->toHaveCount(9)
        ->and(array_unique($insert_levels))->toBe([1]);

    foreach (array_keys(telemetryRowCounts()) as $table) {
        expect(DB::connection('app_watch')->table($table)->sole()->environment_id)->toBe(1);
    }
});

it('takes the environment id from config and ignores any environment_id in the payload', function (): void {
    config(['app-watch.environment_id' => 42]);

    $this->writer->write([storageEvent('request', ['environment_id' => 7])]);

    expect(DB::connection('app_watch')->table('requests')->sole()->environment_id)->toBe(42);
});

it('refuses to write while no environment id is configured', function (): void {
    config(['app-watch.environment_id' => null]);

    $this->writer->write(fixtureBatch()['events']);
})->throws(RuntimeException::class, 'app-watch.environment_id is not configured.');

it('stores each row in the daily partition of its UTC day', function (): void {
    $this->writer->write([
        storageEvent('request', ['recorded_at' => '2026-11-03T23:59:59.999999+00:00', 'path' => '/end-of-day']),
        storageEvent('request', ['recorded_at' => '2026-11-04T07:30:00.000000+08:00', 'path' => '/manila']),
        storageEvent('request', ['recorded_at' => '2026-11-04T00:00:00.000000+00:00', 'path' => '/next-day']),
    ]);

    expect(collect(DB::connection('app_watch')->select('SELECT tableoid::regclass::text AS partition, path FROM requests'))->pluck('partition', 'path')->all())->toBe([
        '/end-of-day' => 'requests_p20261103',
        '/manila' => 'requests_p20261103',
        '/next-day' => 'requests_p20261104',
    ]);
});

it('skips events older than retention_days or further ahead than partitions_ahead_days and stores the rest', function (): void {
    $stats = $this->writer->write([
        storageEvent('request', ['recorded_at' => now()->subDays(15)->format('Y-m-d\TH:i:s.uP')]),
        storageEvent('exception', ['recorded_at' => now()->addDays(8)->format('Y-m-d\TH:i:s.uP')]),
        storageEvent('request'),
        storageEvent('log'),
    ]);

    expect($stats->skipped_retention)->toBe(2)
        ->and($stats->malformed)->toBe(0)
        ->and($stats->stored['request'])->toBe(1)
        ->and($stats->stored['log'])->toBe(1)
        ->and($stats->stored['exception'])->toBe(0)
        ->and(DB::connection('app_watch')->table('requests')->sole()->path)->toBe('/orders/42')
        ->and(DB::connection('app_watch')->table('exception_groups')->count())->toBe(0);
});

it('keeps events exactly on the window bounds', function (): void {
    config(['app-watch.partitions_ahead_days' => 0]);
    app(CreatePartitions::class)->handle(now()->toImmutable()->subDays(14), 0, 'app_watch');

    $stats = $this->writer->write([
        storageEvent('request', ['recorded_at' => now()->format('Y-m-d\TH:i:s.uP'), 'path' => '/newest']),
        storageEvent('request', ['recorded_at' => now()->subDays(14)->format('Y-m-d\TH:i:s.uP'), 'path' => '/oldest']),
        storageEvent('request', ['recorded_at' => now()->addMicrosecond()->format('Y-m-d\TH:i:s.uP')]),
        storageEvent('request', ['recorded_at' => now()->subDays(14)->subMicrosecond()->format('Y-m-d\TH:i:s.uP')]),
    ]);

    expect($stats->skipped_retention)->toBe(2)
        ->and($stats->stored['request'])->toBe(2)
        ->and(DB::connection('app_watch')->table('requests')->orderBy('path')->pluck('path')->all())->toBe(['/newest', '/oldest']);
});

it('counts malformed events and stores the rest of the batch', function (): void {
    $stats = $this->writer->write([
        storageEvent('request', ['type' => 'span']),
        array_diff_key(fixtureEvent('log'), ['recorded_at' => true]),
        storageEvent('query', ['duration_ms' => 'slow']),
        'not an event',
        storageEvent('request'),
        storageEvent('cache'),
    ]);

    expect($stats->malformed)->toBe(4)
        ->and($stats->skipped_retention)->toBe(0)
        ->and($stats->stored['request'])->toBe(1)
        ->and($stats->stored['cache'])->toBe(1)
        ->and(telemetryRowCounts())->toBe([
            'requests' => 1, 'queries' => 0, 'job_runs' => 0, 'outgoing_requests' => 0, 'cache_events' => 1, 'logs' => 0,
            'exception_occurrences' => 0, 'command_runs' => 0, 'exception_groups' => 0,
        ]);
});

it('stores a command event in command_runs with its arguments as jsonb and its exit code and failed flag as sent', function (): void {
    $stats = $this->writer->write([
        storageEvent('command', ['arguments' => ['email' => '?'], 'exit_code' => 2, 'failed' => true]),
    ]);

    $row = DB::connection('app_watch')->table('command_runs')->sole();

    expect($stats->stored['command'])->toBe(1)
        ->and($stats->malformed)->toBe(0)
        ->and($row->environment_id)->toBe(1)
        ->and($row->command)->toBe('orders:sync')
        ->and(DB::connection('app_watch')->scalar('SELECT jsonb_typeof(arguments) FROM command_runs'))->toBe('object')
        ->and(json_decode((string) $row->arguments, true))->toBe(['email' => '?'])
        ->and($row->exit_code)->toBe(2)
        ->and($row->failed)->toBeTrue();
});

it('drops and counts a command event with an out-of-range exit code or nested arguments and stores the rest of the batch', function (array $command_overrides): void {
    $stats = $this->writer->write([storageEvent('command', $command_overrides), storageEvent('request')]);

    expect($stats->malformed)->toBe(1)
        ->and($stats->stored['command'])->toBe(0)
        ->and($stats->stored['request'])->toBe(1)
        ->and(DB::connection('app_watch')->table('command_runs')->count())->toBe(0)
        ->and(storedRequestPaths())->toHaveCount(1);
})->with([
    'exit code 300' => [['exit_code' => 300]],
    'arguments nested two levels deep' => [['arguments' => ['email' => ['nested' => '?']]]],
    'arguments with 101 keys' => [['arguments' => array_fill_keys(array_map(fn (int $index): string => "option{$index}", range(1, EventValidator::MAX_ARGUMENTS + 1)), '?')]],
]);

it('opens no transaction and runs no query when nothing is storable', function (): void {
    $queries = 0;
    DB::connection('app_watch')->listen(function () use (&$queries): void {
        $queries++;
    });

    $stats = $this->writer->write([
        storageEvent('request', ['recorded_at' => now()->subDays(60)->format('Y-m-d\TH:i:s.uP')]),
        storageEvent('request', ['type' => 'span']),
    ]);

    expect($stats->skipped_retention)->toBe(1)
        ->and($stats->malformed)->toBe(1)
        ->and($stats->storedCount())->toBe(0)
        ->and($queries)->toBe(0);
});

it('only queries the tables of the types present in the batch', function (): void {
    $tables = [];
    DB::connection('app_watch')->listen(function (QueryExecuted $query) use (&$tables): void {
        if (preg_match('/^insert into "([a-z_]+)"/', $query->sql, $matches) === 1) {
            $tables[] = $matches[1];
        }
    });

    $this->writer->write([storageEvent('request'), storageEvent('cache')]);

    expect($tables)->toBe(['requests', 'cache_events']);
});

it('groups two identical exceptions into one group with 2 occurrences, and a later batch adds the third', function (): void {
    $this->writer->write([storageEvent('exception'), storageEvent('exception', ['recorded_at' => '2026-11-03T05:42:00.000000+00:00'])]);

    $group = DB::connection('app_watch')->table('exception_groups')->sole();

    expect($group->occurrences)->toBe(2)
        ->and(DB::connection('app_watch')->table('exception_occurrences')->where('exception_group_id', $group->id)->count())->toBe(2);

    $this->writer->write([storageEvent('exception', ['recorded_at' => '2026-11-03T05:55:00.000000+00:00'])]);

    $updated = DB::connection('app_watch')->table('exception_groups')->sole();

    expect($updated->id)->toBe($group->id)
        ->and($updated->occurrences)->toBe(3)
        ->and($updated->first_seen_at)->toBe($group->first_seen_at)
        ->and(Carbon::parse($updated->last_seen_at)->utc()->format('Y-m-d\TH:i:s.uP'))->toBe('2026-11-03T05:55:00.000000+00:00');
});

it('rolls the whole batch back and rethrows when one type fails to insert', function (): void {
    DB::connection('app_watch')->statement('DROP TABLE logs_p20261105');

    try {
        $this->writer->write([
            ...fixtureBatch()['events'],
            storageEvent('log', ['recorded_at' => '2026-11-05T10:00:00.000000+00:00']),
        ]);
    } catch (QueryException $exception) {
        expect($exception->getCode())->toBe('23514')
            ->and(telemetryRowCounts())->toBe(emptyTelemetryCounts());

        return;
    }

    $this->fail('Expected a QueryException.');
});

it('rolls the exception group upsert back with the batch when its occurrence fails to insert', function (): void {
    DB::connection('app_watch')->statement('DROP TABLE exception_occurrences_p20261105');

    expect(fn () => $this->writer->write([
        ...fixtureBatch()['events'],
        storageEvent('exception', ['recorded_at' => '2026-11-05T10:00:00.000000+00:00', 'line' => 999]),
    ]))->toThrow(QueryException::class);

    expect(telemetryRowCounts())->toBe(emptyTelemetryCounts());
});

it('registers the storage classes as singletons', function (string $class): void {
    expect(app($class))->toBe(app($class));
})->with([
    BatchWriter::class,
    EventValidator::class,
    RequestEventWriter::class,
    QueryEventWriter::class,
    JobEventWriter::class,
    OutgoingRequestEventWriter::class,
    CacheEventWriter::class,
    CommandEventWriter::class,
    LogEventWriter::class,
    ExceptionEventWriter::class,
    FingerprintException::class,
    NormalizeExceptionMessage::class,
    UpsertExceptionGroup::class,
]);

it('depends on neither App Watch classes nor laravel-data', function (): void {
    $sources = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src', FilesystemIterator::SKIP_DOTS));

    foreach ($sources as $source) {
        expect(file_get_contents($source->getPathname()))->not->toContain('App\\Features')->not->toContain('Spatie\\LaravelData');
    }

    expect(file_get_contents(dirname(__DIR__, 2).'/composer.json'))->not->toContain('spatie/laravel-data');
});
