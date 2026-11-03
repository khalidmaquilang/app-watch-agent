<?php

declare(strict_types=1);

namespace AppWatch\Agent\Enums;

/**
 * How a queued job run ended.
 */
enum JobStatusEnum: string
{
    case Processed = 'processed';
    case Failed = 'failed';
    case Released = 'released';
}
