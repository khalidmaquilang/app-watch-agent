<?php

declare(strict_types=1);

namespace AppWatch\Agent\Tests\Fixtures\Scheduling;

use Illuminate\Console\Application;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Container\Container;

/**
 * A `->command()` scheduled event that calls its Artisan command in this process, the way the
 * test app cannot run it in a child process (Testbench's skeleton does not know the fixtures).
 */
class InProcessCommandEvent extends Event
{
    /**
     * Call the command (everything after the `php artisan` prefix) through the console kernel.
     *
     * @param  Container  $container
     */
    protected function execute($container): int
    {
        $prefix = Application::phpBinary().' '.Application::artisanBinary().' ';

        return $container->make(Kernel::class)->call(substr((string) $this->command, strlen($prefix)));
    }
}
