<?php

declare(strict_types=1);

namespace AppWatch\Agent\Tests;

use AppWatch\Agent\AppWatchAgentServiceProvider;
use AppWatch\Agent\Transport\FakeTransport;
use AppWatch\Agent\Transport\Transport;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * The test's own spool directory (a fresh temporary directory, removed after the test).
     */
    protected string $spool_path = '';

    /**
     * Swap the transport for an in-memory fake, so flushed traces never reach a real socket.
     * Tests that need the real DaemonTransport bind it themselves. Each test spools into its own
     * temporary directory.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(Transport::class, new FakeTransport);

        $this->spool_path = sys_get_temp_dir().'/app-watch-spool-'.bin2hex(random_bytes(8));
        config(['app-watch.daemon.spool_path' => $this->spool_path]);
    }

    /**
     * Remove the test's spool directory.
     */
    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->spool_path);

        parent::tearDown();
    }

    /**
     * Run every test on Postgres (the raw telemetry tables are range-partitioned, which SQLite cannot do).
     * The connection comes from the DB_* variables in phpunit.xml, so CI and local runs share one setup.
     * The agent itself is configured (environment 1, `app_watch` connection on the same test database)
     * by the APP_WATCH_* variables in phpunit.xml, not here: Testbench registers the package provider,
     * which builds the `app_watch` connection, before this method runs.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'app_watch_agent_testing'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);
    }

    /**
     * Get the package providers registered for every test.
     *
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            AppWatchAgentServiceProvider::class,
        ];
    }
}
