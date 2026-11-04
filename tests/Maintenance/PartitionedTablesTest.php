<?php

declare(strict_types=1);

use AppWatch\Agent\Maintenance\PartitionedTables;
use AppWatch\Agent\Storage\Schema;

it('returns the configured raw telemetry tables', function (): void {
    expect(app(PartitionedTables::class)->handle())->toBe(Schema::PARTITIONED_TABLES);
});

it('returns a configured subset in config order without duplicates', function (): void {
    config(['app-watch.tables' => ['logs', 'requests', 'logs']]);

    expect(app(PartitionedTables::class)->handle())->toBe(['logs', 'requests']);
});

it('returns no tables when none are configured', function (): void {
    config(['app-watch.tables' => []]);

    expect(app(PartitionedTables::class)->handle())->toBe([]);
});

it('rejects a table name outside the whitelist', function (mixed $table): void {
    config(['app-watch.tables' => ['requests', $table]]);

    app(PartitionedTables::class)->handle();
})->with([
    'sql injection' => 'requests; DROP TABLE users',
    'unknown table' => 'other_table',
    'upper case' => 'REQUESTS',
    'not a string' => 42,
])->throws(InvalidArgumentException::class, 'Invalid partitioned table name in app-watch.tables.');

it('rejects a tables setting that is not a list', function (): void {
    config(['app-watch.tables' => 'requests']);

    app(PartitionedTables::class)->handle();
})->throws(InvalidArgumentException::class, 'app-watch.tables must be a list of table names.');
