<?php

declare(strict_types=1);

use AppWatch\Agent\Storage\PartitionDdl;
use AppWatch\Agent\Storage\Schema as TelemetrySchema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::connection('pgsql')->dropAllTables();
});

afterEach(function (): void {
    Schema::connection('pgsql')->dropAllTables();
});

/**
 * The number of daily partitions of the raw telemetry tables on the test database.
 */
function installedPartitionCount(): int
{
    return app(PartitionDdl::class)->count(DB::connection('pgsql'));
}

it('creates the telemetry tables, the schema version and the migrations table', function (): void {
    $this->artisan('app-watch:install', ['--database' => 'pgsql'])
        ->expectsOutputToContain('schema version 1 is installed on [pgsql]')
        ->assertExitCode(0);

    $schema = Schema::connection('pgsql');

    foreach ([...TelemetrySchema::PARTITIONED_TABLES, 'exception_groups', 'rollups_minute', 'rollups_hour', 'app_watch_schema_versions', 'migrations'] as $table) {
        expect($schema->hasTable($table))->toBeTrue("{$table} is missing");
    }

    expect(DB::connection('pgsql')->table('app_watch_schema_versions')->pluck('version')->all())->toBe([1])
        ->and(DB::connection('pgsql')->table('migrations')->count())->toBe(5);
});

it('creates the daily partitions for today through today + partitions_ahead_days', function (): void {
    $this->artisan('app-watch:install', ['--database' => 'pgsql'])->assertExitCode(0);

    $today = CarbonImmutable::now('UTC');

    expect(installedPartitionCount())->toBe(8 * 7);

    foreach (TelemetrySchema::PARTITIONED_TABLES as $table) {
        expect(Schema::connection('pgsql')->hasTable("{$table}_p{$today->format('Ymd')}"))->toBeTrue()
            ->and(Schema::connection('pgsql')->hasTable("{$table}_p{$today->addDays(7)->format('Ymd')}"))->toBeTrue()
            ->and(Schema::connection('pgsql')->hasTable("{$table}_p{$today->addDays(8)->format('Ymd')}"))->toBeFalse();
    }
});

it('uses the configured partitions_ahead_days', function (): void {
    config(['app-watch.partitions_ahead_days' => 2]);

    $this->artisan('app-watch:install', ['--database' => 'pgsql'])->assertExitCode(0);

    expect(installedPartitionCount())->toBe(3 * 7);
});

it('migrates nothing and creates no partitions when run again', function (): void {
    $this->artisan('app-watch:install', ['--database' => 'pgsql'])->assertExitCode(0);
    $partition_count = installedPartitionCount();

    $this->artisan('app-watch:install', ['--database' => 'pgsql'])
        ->expectsOutputToContain('Nothing to migrate')
        ->expectsOutputToContain('Created 0 telemetry partitions')
        ->assertExitCode(0);

    expect(installedPartitionCount())->toBe($partition_count)
        ->and(DB::connection('pgsql')->table('app_watch_schema_versions')->count())->toBe(1)
        ->and(DB::connection('pgsql')->table('migrations')->count())->toBe(5);
});

it('skips a partition that already exists', function (): void {
    $this->artisan('app-watch:install', ['--database' => 'pgsql'])->assertExitCode(0);
    $partition = 'logs_p'.CarbonImmutable::now('UTC')->addDays(3)->format('Ymd');
    DB::connection('pgsql')->statement("DROP TABLE {$partition}");

    $this->artisan('app-watch:install', ['--database' => 'pgsql'])
        ->expectsOutputToContain('Created 1 telemetry partitions')
        ->assertExitCode(0);

    expect(Schema::connection('pgsql')->hasTable($partition))->toBeTrue()
        ->and(installedPartitionCount())->toBe(8 * 7);
});

it('installs on the --database connection, not the default one', function (): void {
    config([
        'database.connections.unused_default' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'database.default' => 'unused_default',
    ]);

    $this->artisan('app-watch:install', ['--database' => 'pgsql'])->assertExitCode(0);

    expect(Schema::connection('pgsql')->hasTable('requests'))->toBeTrue()
        ->and(Schema::connection('unused_default')->hasTable('migrations'))->toBeFalse();
});

it('defaults to the app-watch.connection connection', function (): void {
    config([
        'database.connections.client' => config('database.connections.pgsql'),
        'app-watch.connection' => 'client',
    ]);

    $this->artisan('app-watch:install')
        ->expectsOutputToContain('is installed on [client]')
        ->assertExitCode(0);

    expect(Schema::connection('pgsql')->hasTable('app_watch_schema_versions'))->toBeTrue();
});

it('fails without changing anything when the connection does not exist', function (): void {
    $this->artisan('app-watch:install', ['--database' => 'missing_connection'])
        ->expectsOutputToContain('Database connection [missing_connection] is not configured')
        ->assertExitCode(1);

    expect(Schema::connection('pgsql')->getTableListing())->toBe([]);
});
