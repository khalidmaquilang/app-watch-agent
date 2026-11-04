<?php

declare(strict_types=1);

namespace AppWatch\Agent\Maintenance;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * Builds the minute rollups of the last closed minutes in the client's database.
 */
final class BuildMinuteRollups
{
    /**
     * The rollup table minute buckets are written to.
     */
    public const string ROLLUP_TABLE = 'rollups_minute';

    /**
     * The bucket size of minute rollups.
     */
    public const string BUCKET_UNIT = 'minute';

    /**
     * The grace window used when `app-watch.rollups.grace_minutes` is not numeric.
     */
    public const int DEFAULT_GRACE_MINUTES = 2;

    /**
     * Create the minute rollup builder.
     */
    public function __construct(
        protected readonly BuildRollupsForRange $build_rollups_for_range,
        protected readonly Repository $config,
    ) {}

    /**
     * Build the minute rollups of the last `app-watch.rollups.grace_minutes` closed minutes, i.e. the range
     * [floor_minute(now) − grace_minutes, floor_minute(now)), and return how many rows were upserted.
     *
     * The current (open) minute is never aggregated. Closed minutes inside the grace window are
     * re-aggregated on every run so late events are counted; re-running is idempotent. A grace window
     * below 1 is treated as 1 so the last closed minute is always built.
     *
     * @throws InvalidArgumentException when the rollup range is invalid
     */
    public function handle(?CarbonImmutable $now = null, ?string $connection_name = null): int
    {
        $configured_grace_minutes = $this->config->get('app-watch.rollups.grace_minutes', self::DEFAULT_GRACE_MINUTES);
        $grace_minutes = max(1, is_numeric($configured_grace_minutes) ? (int) $configured_grace_minutes : self::DEFAULT_GRACE_MINUTES);
        $to = ($now ?? CarbonImmutable::now())->utc()->startOfMinute();
        $from = $to->subMinutes($grace_minutes);

        return $this->build_rollups_for_range->handle(self::ROLLUP_TABLE, $from, $to, self::BUCKET_UNIT, $connection_name);
    }
}
