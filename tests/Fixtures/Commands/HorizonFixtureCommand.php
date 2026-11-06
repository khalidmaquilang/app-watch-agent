<?php

declare(strict_types=1);

namespace AppWatch\Agent\Tests\Fixtures\Commands;

use Illuminate\Console\Command;

/**
 * A test command whose name matches the default `horizon*` ignore glob.
 */
class HorizonFixtureCommand extends Command
{
    /**
     * {@inheritDoc}
     */
    protected $signature = 'horizon:fixture';

    /**
     * {@inheritDoc}
     */
    protected $description = 'A test command matching an ignored command glob.';

    /**
     * Do nothing.
     */
    public function handle(): int
    {
        return 0;
    }
}
