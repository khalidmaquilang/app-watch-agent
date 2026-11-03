<?php

declare(strict_types=1);

use AppWatch\Agent\Enums\JobStatusEnum;
use AppWatch\Agent\Enums\TelemetryTypeEnum;
use AppWatch\Agent\Payloads\JobEventPayload;

it('serializes to the v1 job event contract', function (): void {
    $fixture = fixtureEvent('job');

    $payload = new JobEventPayload(
        metadata: fixtureMetadata($fixture),
        job_class: 'App\\Jobs\\SendOrderConfirmationJob',
        queue: 'default',
        connection: 'redis',
        status: JobStatusEnum::Processed,
        attempt: 1,
        duration_ms: 312.5,
    );

    expect($payload->type())->toBe(TelemetryTypeEnum::Job);
    expectMatchesContract($payload->toArray(), $fixture);
});
