<?php

declare(strict_types=1);

namespace AppWatch\Agent\Tests;

use AppWatch\Agent\AppWatchAgentServiceProvider;
use AppWatch\Agent\Transport\FakeTransport;
use AppWatch\Agent\Transport\Transport;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * Swap the transport for an in-memory fake, so flushed traces never reach a real socket.
     * Tests that need the real DaemonTransport bind it themselves.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(Transport::class, new FakeTransport);
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
