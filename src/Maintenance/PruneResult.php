<?php

declare(strict_types=1);

namespace AppWatch\Agent\Maintenance;

/**
 * The outcome of pruning expired rollups.
 */
final readonly class PruneResult
{
    /**
     * The number of rows deleted from `rollups_minute` and from `rollups_hour`.
     */
    public function __construct(
        public int $minute_deleted,
        public int $hour_deleted,
    ) {}
}
