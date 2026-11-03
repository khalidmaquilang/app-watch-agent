<?php

declare(strict_types=1);

use AppWatch\Agent\Enums\CacheOperationEnum;
use AppWatch\Agent\Enums\TelemetryTypeEnum;
use AppWatch\Agent\Payloads\CacheEventPayload;

it('serializes to the v1 cache event contract', function (): void {
    $fixture = fixtureEvent('cache');

    $payload = new CacheEventPayload(
        metadata: fixtureMetadata($fixture),
        store: 'redis',
        key: 'orders:42',
        operation: CacheOperationEnum::Miss,
        duration_ms: 0.31,
    );

    expect($payload->type())->toBe(TelemetryTypeEnum::Cache);
    expectMatchesContract($payload->toArray(), $fixture);
});
