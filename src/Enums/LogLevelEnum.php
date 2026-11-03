<?php

declare(strict_types=1);

namespace AppWatch\Agent\Enums;

/**
 * The PSR-3 log levels.
 */
enum LogLevelEnum: string
{
    case Emergency = 'emergency';
    case Alert = 'alert';
    case Critical = 'critical';
    case Error = 'error';
    case Warning = 'warning';
    case Notice = 'notice';
    case Info = 'info';
    case Debug = 'debug';

    /**
     * The RFC 5424 severity; lower is more severe (emergency = 0, debug = 7).
     */
    public function severity(): int
    {
        return match ($this) {
            self::Emergency => 0,
            self::Alert => 1,
            self::Critical => 2,
            self::Error => 3,
            self::Warning => 4,
            self::Notice => 5,
            self::Info => 6,
            self::Debug => 7,
        };
    }

    /**
     * Whether this level is at least as severe as the given threshold.
     */
    public function isAtLeast(self $threshold): bool
    {
        return $this->severity() <= $threshold->severity();
    }
}
