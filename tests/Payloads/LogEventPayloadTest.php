<?php

declare(strict_types=1);

use AppWatch\Agent\Enums\LogLevelEnum;
use AppWatch\Agent\Enums\TelemetryTypeEnum;
use AppWatch\Agent\Payloads\LogEventPayload;

it('serializes to the v1 log event contract', function (): void {
    $fixture = fixtureEvent('log');

    $payload = new LogEventPayload(
        metadata: fixtureMetadata($fixture),
        level: LogLevelEnum::Warning,
        message: 'Payment provider responded slowly',
        context: ['order_id' => 42, 'provider' => 'payments'],
    );

    expect($payload->type())->toBe(TelemetryTypeEnum::Log);
    expectMatchesContract($payload->toArray(), $fixture);
});
