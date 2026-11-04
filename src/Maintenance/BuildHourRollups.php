<?php

declare(strict_types=1);

namespace AppWatch\Agent\Maintenance;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * Builds the hour rollups of the last closed hour in the client's database.
 */
final class BuildHourRollups
{
    /**
     * The rollup table hour buckets are written to.
     */
    public const string ROLLUP_TABLE = 'rollups_hour';

    /**
     * The bucket size of hour rollups.
     */
    public const string BUCKET_UNIT = 'hour';

    /**
     * Create the hour rollup builder.
     */
    public function __construct(
        protected readonly BuildRollupsForRange $build_rollups_for_range,
        protected readonly Repository $config,
    ) {}

    /**
     * Build the hour rollups of the last closed hour, i.e. the range [floor_hour(now) − 1 hour, floor_hour(now)),
     * from the raw telemetry (never from minute rollups, because percentiles cannot be merged) and return how
     * many rows were upserted.
     *
     * When `now` is less than `app-watch.rollups.grace_minutes` after the hour boundary, the hour before the last
     * closed hour is re-aggregated as well, so events arriving late for it are still counted. The current (open)
     * hour is never aggregated, and re-running is idempotent.
     *
     * @throws InvalidArgumentException when the rollup range is invalid
     */
    public function handle(?CarbonImmutable $now = null, ?string $connection_name = null): int
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $configured_grace_minutes = $this->config->get('app-watch.rollups.grace_minutes', BuildMinuteRollups::DEFAULT_GRACE_MINUTES);
        $grace_minutes = max(0, is_numeric($configured_grace_minutes) ? (int) $configured_grace_minutes : BuildMinuteRollups::DEFAULT_GRACE_MINUTES);
        $to = $now->startOfHour();
        $from = $to->subHour();

        if ($now->lessThan($to->addMinutes($grace_minutes))) {
            $from = $from->subHour();
        }

        return $this->build_rollups_for_range->handle(self::ROLLUP_TABLE, $from, $to, self::BUCKET_UNIT, $connection_name);
    }
}
