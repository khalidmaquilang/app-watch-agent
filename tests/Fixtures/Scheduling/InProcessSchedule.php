<?php

declare(strict_types=1);

namespace AppWatch\Agent\Tests\Fixtures\Scheduling;

use Illuminate\Console\Application;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/**
 * A schedule that can also hold command events run in this process (see InProcessCommandEvent).
 */
class InProcessSchedule extends Schedule
{
    /**
     * Schedule an Artisan command that runs in this process instead of a child process.
     */
    public function inProcessCommand(string $command): Event
    {
        $this->events[] = $event = new InProcessCommandEvent($this->eventMutex, Application::formatCommandString($command), $this->timezone);

        return $event;
    }
}
