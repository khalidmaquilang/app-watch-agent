<?php

declare(strict_types=1);

use AppWatch\Agent\Enums\TelemetryTypeEnum;
use AppWatch\Agent\Support\Sampler;

it('never samples a type with rate 0', function (): void {
    config(['app-watch.sample_rates.request' => 0]);
    $sampler = app(Sampler::class);

    expect(collect(range(1, 100))->filter(fn (): bool => $sampler->shouldSample(TelemetryTypeEnum::Request)))->toBeEmpty();
});

it('always samples a type with rate 1', function (): void {
    config(['app-watch.sample_rates.query' => 1.0]);
    $sampler = app(Sampler::class);

    expect(collect(range(1, 100))->reject(fn (): bool => $sampler->shouldSample(TelemetryTypeEnum::Query)))->toBeEmpty();
});

it('gives deterministic results for a seeded sampler at rate 0.5', function (): void {
    config(['app-watch.sample_rates.request' => 0.5]);

    $draw = function (): array {
        $sampler = Sampler::seeded(config(), 42);

        return array_map(fn (): bool => $sampler->shouldSample(TelemetryTypeEnum::Request), range(1, 50));
    };

    $first_run = $draw();

    expect($draw())->toBe($first_run)
        ->and($first_run)->toContain(true)
        ->and($first_run)->toContain(false);
});

it('always samples exceptions whatever their configured rate', function (): void {
    config(['app-watch.sample_rates.exception' => 0]);

    expect(app(Sampler::class)->rate(TelemetryTypeEnum::Exception))->toBe(1.0)
        ->and(app(Sampler::class)->shouldSample(TelemetryTypeEnum::Exception))->toBeTrue();
});

it('clamps out-of-range rates and treats invalid ones as 1', function (mixed $configured, float $expected): void {
    config(['app-watch.sample_rates.cache' => $configured]);

    expect(app(Sampler::class)->rate(TelemetryTypeEnum::Cache))->toBe($expected);
})->with([
    'above 1' => [5, 1.0],
    'below 0' => [-1, 0.0],
    'numeric string' => ['0.25', 0.25],
    'non-numeric' => ['often', 1.0],
    'missing' => [null, 1.0],
]);
