<?php

declare(strict_types=1);

namespace AppWatch\Agent\Enums;

/**
 * The event types of the v1 ingest contract.
 */
enum TelemetryTypeEnum: string
{
    case Request = 'request';
    case Query = 'query';
    case Job = 'job';
    case OutgoingRequest = 'outgoing_request';
    case Cache = 'cache';
    case Log = 'log';
    case Exception = 'exception';
}
