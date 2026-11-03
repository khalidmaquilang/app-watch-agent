<?php

declare(strict_types=1);

namespace AppWatch\Agent\Support;

use AppWatch\Agent\Enums\TelemetryTypeEnum;
use Illuminate\Contracts\Config\Repository;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Decides per event type whether to record, using the configured `sample_rates`.
 */
final readonly class Sampler
{
    /**
     * Use a secure random engine unless a randomizer is given (see seeded()).
     */
    public function __construct(
        private Repository $config,
        private Randomizer $randomizer = new Randomizer,
    ) {}

    /**
     * A sampler with deterministic results for the given seed (for tests).
     */
    public static function seeded(Repository $config, int $seed): self
    {
        return new self($config, new Randomizer(new Mt19937($seed)));
    }

    /**
     * The configured rate for the type, clamped to 0–1; exceptions are always 1.0 and a
     * missing or non-numeric rate counts as 1.0.
     */
    public function rate(TelemetryTypeEnum $type): float
    {
        if ($type === TelemetryTypeEnum::Exception) {
            return 1.0;
        }

        $rate = $this->config->get('app-watch.sample_rates.'.$type->value, 1.0);

        if (! is_int($rate) && ! is_float($rate) && ! (is_string($rate) && is_numeric($rate))) {
            return 1.0;
        }

        return max(0.0, min(1.0, (float) $rate));
    }

    /**
     * Whether an event (or, for requests and jobs, a whole trace) of this type is recorded.
     */
    public function shouldSample(TelemetryTypeEnum $type): bool
    {
        $rate = $this->rate($type);

        if ($rate <= 0.0) {
            return false;
        }

        if ($rate >= 1.0) {
            return true;
        }

        return $this->randomizer->nextFloat() < $rate;
    }
}
