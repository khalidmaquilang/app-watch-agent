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
