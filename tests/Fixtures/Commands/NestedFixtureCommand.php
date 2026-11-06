<?php

declare(strict_types=1);

namespace AppWatch\Agent\Tests\Fixtures\Commands;

use Illuminate\Console\Command;

/**
 * A test command that does nothing, called from inside ReportCommand.
 */
class NestedFixtureCommand extends Command
{
    /**
     * {@inheritDoc}
     */
    protected $signature = 'fixture:nested';

    /**
     * {@inheritDoc}
     */
    protected $description = 'A test command run inside another command.';

    /**
     * Do nothing.
     */
    public function handle(): int
    {
        return 0;
    }
}
