<?php

declare(strict_types=1);

namespace AppWatch\Agent\Enums;

/**
 * What a scheduled task runs: an Artisan command (`->command()`), a job (`->job()`), a closure or
 * callable (`->call()`) or a shell command (`->exec()`).
 */
enum ScheduledTaskKindEnum: string
{
    case Command = 'command';
    case Job = 'job';
    case Closure = 'closure';
    case Exec = 'exec';
}
