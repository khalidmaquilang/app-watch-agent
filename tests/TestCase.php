<?php

declare(strict_types=1);

namespace AppWatch\Agent\Tests;

use AppWatch\Agent\AppWatchAgentServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
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
