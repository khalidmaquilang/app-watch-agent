<?php

declare(strict_types=1);

use AppWatch\Agent\AppWatchAgentServiceProvider;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

afterEach(function (): void {
    File::delete(config_path('app-watch.php'));
    File::delete(app()->getCachedConfigPath());
});

/**
 * Load the package's config file with the given environment variables set (or unset when null),
 * then restore them.
 *
 * @param  array<string, string|null>  $variables
 * @return array<string, mixed>
 */
function loadAgentConfig(array $variables): array
{
    return withEnvironmentVariables($variables, fn (): array => require __DIR__.'/../config/app-watch.php');
}

it('merges the default config', function (): void {
    expect(config('app-watch.enabled'))->toBeTrue()
        ->and(config()->has('app-watch.token'))->toBeFalse()
        ->and(Arr::except(config('app-watch.daemon'), 'spool_path'))->toBe(['host' => '127.0.0.1', 'port' => 2407, 'flush_events' => 500, 'flush_seconds' => 5, 'max_buffered_events' => 10000, 'spool_max_bytes' => 104857600])
        ->and((require __DIR__.'/../config/app-watch.php')['daemon']['spool_path'])->toBe(storage_path('app/app-watch/spool'))
        ->and(config('app-watch.sample_rates.exception'))->toBe(1.0)
        ->and(config('app-watch.ignore.paths'))->toBe(['/up', '/telescope*', '/horizon*'])
        ->and(config('app-watch.log_level'))->toBe('warning')
        ->and(config('app-watch.deploy'))->toBeNull()
        ->and(config('app-watch.connection'))->toBe('app_watch')
        ->and(config('app-watch.retention_days'))->toBe(14)
        ->and(config('app-watch.partitions_ahead_days'))->toBe(7)
        ->and(config('app-watch.rollups'))->toBe(['grace_minutes' => 2, 'minute_retention_days' => 7, 'hour_retention_days' => 90])
        ->and(config('app-watch.tables'))->toBe(['requests', 'queries', 'job_runs', 'outgoing_requests', 'cache_events', 'logs', 'exception_occurrences']);
});

it('reads the environment id as an integer and leaves it null while unset', function (?string $value, ?int $expected): void {
    expect(loadAgentConfig(['APP_WATCH_ENVIRONMENT_ID' => $value])['environment_id'])->toBe($expected);
})->with([
    'unset' => [null, null],
    'numeric' => ['42', 42],
    'zero' => ['0', 0],
    'non-numeric' => ['abc', 0],
]);

it('reads the testing environment id from phpunit.xml as an integer', function (): void {
    expect(config('app-watch.environment_id'))->toBe(1);
});

it('defaults the connection and database settings', function (): void {
    $config = loadAgentConfig([
        'APP_WATCH_DB_CONNECTION' => null,
        'APP_WATCH_DB_HOST' => null,
        'APP_WATCH_DB_PORT' => null,
        'APP_WATCH_DB_DATABASE' => null,
        'APP_WATCH_DB_USERNAME' => null,
        'APP_WATCH_DB_PASSWORD' => null,
        'APP_WATCH_DB_SSLMODE' => null,
    ]);

    expect($config['connection'])->toBe('app_watch')
        ->and($config['database'])->toBe(['host' => null, 'port' => 5432, 'database' => null, 'username' => null, 'password' => null, 'sslmode' => 'require']);
});

it('reads the connection, database, retention and rollup settings from the environment', function (): void {
    $config = loadAgentConfig([
        'APP_WATCH_DB_CONNECTION' => 'telemetry',
        'APP_WATCH_DB_HOST' => 'db.example.com',
        'APP_WATCH_DB_PORT' => '6543',
        'APP_WATCH_DB_DATABASE' => 'telemetry',
        'APP_WATCH_DB_USERNAME' => 'agent',
        'APP_WATCH_DB_PASSWORD' => 'secret',
        'APP_WATCH_DB_SSLMODE' => 'verify-full',
        'APP_WATCH_RETENTION_DAYS' => '30',
        'APP_WATCH_PARTITIONS_AHEAD_DAYS' => '3',
        'APP_WATCH_ROLLUPS_GRACE_MINUTES' => '5',
        'APP_WATCH_ROLLUPS_MINUTE_RETENTION_DAYS' => '2',
        'APP_WATCH_ROLLUPS_HOUR_RETENTION_DAYS' => '30',
    ]);

    expect($config['connection'])->toBe('telemetry')
        ->and($config['database'])->toBe(['host' => 'db.example.com', 'port' => 6543, 'database' => 'telemetry', 'username' => 'agent', 'password' => 'secret', 'sslmode' => 'verify-full'])
        ->and($config['retention_days'])->toBe(30)
        ->and($config['partitions_ahead_days'])->toBe(3)
        ->and($config['rollups'])->toBe(['grace_minutes' => 5, 'minute_retention_days' => 2, 'hour_retention_days' => 30]);
});

it('can be cached with php artisan config:cache', function (): void {
    // config:cache builds its config from a fresh Testbench skeleton app, which only loads the
    // providers listed in the skeleton's cached testbench.yaml.
    $testbench_yaml = app()->bootstrapPath('cache/testbench.yaml');
    File::put($testbench_yaml, "providers:\n  - ".AppWatchAgentServiceProvider::class."\n");

    try {
        $this->artisan('config:cache')->assertSuccessful();
    } finally {
        File::delete($testbench_yaml);
    }

    $cached = require app()->getCachedConfigPath();

    expect($cached['app-watch']['environment_id'])->toBe(1)
        ->and($cached['app-watch']['connection'])->toBe('app_watch')
        ->and($cached['database']['connections']['app_watch']['database'])->toBe('app_watch_agent_testing');
});

it('publishes config/app-watch.php with the app-watch-config tag', function (): void {
    File::delete(config_path('app-watch.php'));

    $this->artisan('vendor:publish', ['--tag' => 'app-watch-config'])->assertSuccessful();

    expect(File::exists(config_path('app-watch.php')))->toBeTrue();

    $published = require config_path('app-watch.php');

    expect(array_keys($published))->toBe([
        'enabled', 'environment_id', 'connection', 'database', 'retention_days', 'partitions_ahead_days', 'rollups', 'tables',
        'daemon', 'sample_rates', 'ignore', 'log_level', 'deploy',
    ])
        ->and(array_keys($published['database']))->toBe(['host', 'port', 'database', 'username', 'password', 'sslmode'])
        ->and(array_keys($published['rollups']))->toBe(['grace_minutes', 'minute_retention_days', 'hour_retention_days'])
        ->and(array_keys($published['daemon']))->toBe(['host', 'port', 'flush_events', 'flush_seconds', 'max_buffered_events', 'spool_path', 'spool_max_bytes'])
        ->and(array_keys($published['sample_rates']))->toBe(['request', 'query', 'job', 'outgoing_request', 'cache', 'log', 'exception'])
        ->and(array_keys($published['ignore']))->toBe(['paths', 'jobs', 'queries']);
});

it('runs the suite on the Postgres app_watch_agent_testing database', function (): void {
    $connection = DB::connection();

    expect(config('database.default'))->toBe('pgsql')
        ->and($connection->getDriverName())->toBe('pgsql')
        ->and($connection->getDatabaseName())->toBe('app_watch_agent_testing')
        ->and($connection->scalar('select 1'))->toBe(1);
});
