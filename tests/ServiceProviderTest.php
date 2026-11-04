<?php

declare(strict_types=1);

use AppWatch\Agent\AppWatchAgentServiceProvider;
use AppWatch\Agent\Support\AgentConfiguration;
use AppWatch\Agent\Transport\NullTransport;
use AppWatch\Agent\Transport\Transport;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\DB;

/**
 * Register the agent's provider again on the current app (after the test changed the config).
 */
function registerAgentProvider(): void
{
    (new AppWatchAgentServiceProvider(app()))->register();
}

it('registers the agent service provider', function (): void {
    expect(app()->getProviders(AppWatchAgentServiceProvider::class))->not->toBeEmpty();
});

it('registers the app_watch connection as pgsql from the APP_WATCH_DB_* variables', function (): void {
    expect(config('database.connections.app_watch'))->toBe([
        'driver' => 'pgsql',
        'host' => '127.0.0.1',
        'port' => 5432,
        'database' => 'app_watch_agent_testing',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8',
        'prefix' => '',
        'search_path' => 'public',
        'sslmode' => 'prefer',
    ])
        ->and(DB::connection('app_watch')->scalar('select 1'))->toBe(1);
});

it('registers the connection under the configured name from the database settings', function (): void {
    config([
        'app-watch.connection' => 'telemetry',
        'app-watch.database' => ['host' => 'db.example.com', 'port' => 6543, 'database' => 'telemetry', 'username' => 'agent', 'password' => 'secret', 'sslmode' => 'require'],
    ]);

    registerAgentProvider();

    expect(config('database.connections.telemetry'))->toBe([
        'driver' => 'pgsql',
        'host' => 'db.example.com',
        'port' => 6543,
        'database' => 'telemetry',
        'username' => 'agent',
        'password' => 'secret',
        'charset' => 'utf8',
        'prefix' => '',
        'search_path' => 'public',
        'sslmode' => 'require',
    ]);
});

it('leaves a connection the host app already defines untouched', function (): void {
    $host_connection = ['driver' => 'pgsql', 'host' => 'host-db', 'database' => 'host_telemetry', 'sslmode' => 'disable'];
    config([
        'database.connections.app_watch' => $host_connection,
        'app-watch.database' => ['host' => 'other-db', 'port' => 5432, 'database' => 'other', 'username' => 'agent', 'password' => 'secret', 'sslmode' => 'require'],
    ]);

    registerAgentProvider();

    expect(config('database.connections.app_watch'))->toBe($host_connection);
});

it('uses the host app connection as-is when APP_WATCH_DB_CONNECTION names it', function (): void {
    $pgsql = config('database.connections.pgsql');
    config([
        'app-watch.connection' => 'pgsql',
        'app-watch.database' => ['host' => 'other-db', 'port' => 5432, 'database' => 'other', 'username' => 'agent', 'password' => 'secret', 'sslmode' => 'require'],
    ]);

    registerAgentProvider();

    expect(config('database.connections.pgsql'))->toBe($pgsql)
        ->and(app(AgentConfiguration::class)->isConfigured())->toBeTrue();
});

it('registers no connection without a database name', function (mixed $database): void {
    config([
        'app-watch.connection' => 'telemetry',
        'app-watch.database' => ['host' => 'db.example.com', 'port' => 5432, 'database' => $database, 'username' => 'agent', 'password' => 'secret', 'sslmode' => 'require'],
    ]);

    registerAgentProvider();

    expect(config('database.connections.telemetry'))->toBeNull();
})->with(['missing' => [null], 'empty' => ['']]);

it('boots silently while the environment id is missing or invalid', function (?string $environment_id): void {
    withEnvironmentVariables(['APP_WATCH_ENVIRONMENT_ID' => $environment_id], function (): void {
        $this->refreshApplication();
    });

    expect(app(AgentConfiguration::class)->isConfigured())->toBeFalse()
        ->and(app(Transport::class))->toBeInstanceOf(NullTransport::class)
        ->and(app('events')->hasListeners(RequestHandled::class))->toBeFalse();
})->with(['missing' => [null], 'zero' => ['0'], 'non-numeric' => ['abc']]);

it('boots silently while no telemetry database is configured', function (): void {
    withEnvironmentVariables(['APP_WATCH_DB_DATABASE' => null], function (): void {
        $this->refreshApplication();
    });

    expect(config('database.connections.app_watch'))->toBeNull()
        ->and(app(AgentConfiguration::class)->isConfigured())->toBeFalse()
        ->and(app(Transport::class))->toBeInstanceOf(NullTransport::class)
        ->and(app('events')->hasListeners(RequestHandled::class))->toBeFalse();
});

it('registers tracing while configured', function (): void {
    expect(app('events')->hasListeners(RequestHandled::class))->toBeTrue();
});
