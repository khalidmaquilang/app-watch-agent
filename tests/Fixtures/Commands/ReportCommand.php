<?php

declare(strict_types=1);

namespace AppWatch\Agent\Tests\Fixtures\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * A test command with an argument and options; it can exit with a given code, throw, or call
 * another command.
 */
class ReportCommand extends Command
{
    /**
     * {@inheritDoc}
     */
    protected $signature = 'fixture:report {email?} {--token=} {--exit=} {--throw} {--call=} {--dry-run}';

    /**
     * {@inheritDoc}
     */
    protected $description = 'A test command for the agent.';

    /**
     * Sleep a moment, then call the nested command, throw or exit with the given code.
     */
    public function handle(): int
    {
        usleep(1000);

        $nested_command = $this->option('call');

        if (is_string($nested_command) && $nested_command !== '') {
            Artisan::call($nested_command);
        }

        if ($this->option('throw') === true) {
            throw new RuntimeException('Report failed on purpose.');
        }

        return (int) $this->option('exit');
    }
}
