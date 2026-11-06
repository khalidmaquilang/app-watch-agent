<?php

declare(strict_types=1);

namespace AppWatch\Agent\Enums;

/**
 * How a scheduled task run ended.
 */
enum ScheduledTaskOutcomeEnum: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
