<?php

declare(strict_types=1);

use AppWatch\Agent\Storage\Schema as TelemetrySchema;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::connection('pgsql')->dropAllTables();
    $this->artisan('app-watch:install', ['--database' => 'pgsql'])->assertExitCode(0);
});

afterEach(function (): void {
    Schema::connection('pgsql')->dropAllTables();
});

/**
 * The column lists of a table's indexes, e.g. ['environment_id,recorded_at'].
 *
 * @return list<string>
 */
function telemetryIndexColumns(string $table): array
{
    return collect(Schema::connection('pgsql')->getIndexes($table))
        ->map(fn (array $index): string => implode(',', $index['columns']))
        ->values()
        ->all();
}

it('declares every raw telemetry table as partitioned by range on recorded_at', function (string $table): void {
    $partitioning = DB::connection('pgsql')->selectOne(
        "SELECT c.relkind, p.partstrat, pg_get_partkeydef(c.oid) AS partition_key
         FROM pg_class c JOIN pg_partitioned_table p ON p.partrelid = c.oid
         WHERE c.relname = ? AND c.relnamespace = 'public'::regnamespace",
        [$table],
    );

    expect($partitioning)->not->toBeNull()
        ->and($partitioning->relkind)->toBe('p')
        ->and($partitioning->partstrat)->toBe('r')
        ->and($partitioning->partition_key)->toBe('RANGE (recorded_at)');
})->with(TelemetrySchema::PARTITIONED_TABLES);

it('has a composite (id, recorded_at) primary key, the environment indexes and identity ids', function (string $table): void {
    $primary_key = collect(Schema::connection('pgsql')->getIndexes($table))->firstWhere('primary', true);
    $columns = collect(Schema::connection('pgsql')->getColumns($table))->keyBy('name');

    expect($primary_key['columns'] ?? null)->toBe(['id', 'recorded_at'])
        ->and(telemetryIndexColumns($table))->toContain('environment_id,recorded_at', 'environment_id,trace_id')
        ->and($columns['trace_id']['type'])->toBe('uuid')
        ->and($columns['recorded_at']['type'])->toBe('timestamp(6) with time zone')
        ->and($columns['created_at']['default'])->toBe('now()')
        ->and(DB::connection('pgsql')->selectOne(
            "SELECT attidentity FROM pg_attribute WHERE attrelid = ?::regclass AND attname = 'id'",
            [$table],
        )->attidentity)->toBe('a');
})->with(TelemetrySchema::PARTITIONED_TABLES);

it('indexes exception occurrences by group and time', function (): void {
    expect(telemetryIndexColumns('exception_occurrences'))->toContain('exception_group_id,recorded_at');
});

it('has no foreign key constraints on the exception group and rollup tables', function (string $table): void {
    $foreign_key_count = DB::connection('pgsql')->scalar(
        "SELECT count(*) FROM pg_constraint WHERE conrelid = ?::regclass AND contype = 'f'",
        [$table],
    );

    expect($foreign_key_count)->toBe(0)
        ->and(collect(Schema::connection('pgsql')->getColumns($table))->firstWhere('name', 'environment_id')['type'] ?? null)->toBe('bigint');
})->with(['exception_groups', 'rollups_minute', 'rollups_hour']);

it('keeps the exception group indexes', function (): void {
    expect(telemetryIndexColumns('exception_groups'))->toContain('environment_id,fingerprint', 'environment_id,last_seen_at')
        ->and(collect(Schema::connection('pgsql')->getIndexes('exception_groups'))
            ->filter(fn (array $index): bool => $index['unique'] && ! $index['primary'])
            ->map(fn (array $index): string => implode(',', $index['columns']))
            ->values()
            ->all())->toBe(['environment_id,fingerprint']);
});

it('keeps the rollup indexes', function (string $table): void {
    expect(telemetryIndexColumns($table))->toContain('environment_id,type,key,bucket', 'environment_id,type,bucket')
        ->and(collect(Schema::connection('pgsql')->getIndexes($table))
            ->filter(fn (array $index): bool => $index['unique'] && ! $index['primary'])
            ->map(fn (array $index): string => implode(',', $index['columns']))
            ->values()
            ->all())->toBe(['environment_id,type,key,bucket']);
})->with(['rollups_minute', 'rollups_hour']);

it('records the schema version with its install time', function (): void {
    $row = DB::connection('pgsql')->table(TelemetrySchema::VERSIONS_TABLE)->sole();

    expect($row->version)->toBe(1)
        ->and($row->installed_at)->not->toBeNull()
        ->and(collect(Schema::connection('pgsql')->getIndexes(TelemetrySchema::VERSIONS_TABLE))->firstWhere('primary', true)['columns'] ?? null)->toBe(['version']);
});

it('accepts a row inside a partition and rejects one without a partition', function (): void {
    $row = [
        'environment_id' => 1,
        'trace_id' => '0b6f5a6e-3c1d-4e7a-9a59-0a2f1d7c8e11',
        'host' => 'web-1',
        'level' => 'error',
        'message' => 'partition check',
    ];

    DB::connection('pgsql')->table('logs')->insert([...$row, 'recorded_at' => now('UTC')]);

    expect(DB::connection('pgsql')->table('logs')->count())->toBe(1);

    DB::connection('pgsql')->table('logs')->insert([...$row, 'recorded_at' => now('UTC')->addDays(30)]);
})->throws(QueryException::class, 'no partition of relation "logs" found for row');

it('references no App Watch classes from the migrations', function (): void {
    foreach (glob(TelemetrySchema::migrationsPath().'/*.php') ?: [] as $migration) {
        expect(file_get_contents($migration))->not->toContain('App\\Features');
    }
});
