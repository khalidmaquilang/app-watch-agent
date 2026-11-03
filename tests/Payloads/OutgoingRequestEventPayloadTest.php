<?php

declare(strict_types=1);

use AppWatch\Agent\Enums\TelemetryTypeEnum;
use AppWatch\Agent\Payloads\OutgoingRequestEventPayload;

it('serializes to the v1 outgoing request event contract', function (): void {
    $fixture = fixtureEvent('outgoing_request');

    $payload = new OutgoingRequestEventPayload(
        metadata: fixtureMetadata($fixture),
        method: 'POST',
        target_host: 'api.payments.example',
        url: 'https://api.payments.example/v2/charges',
        status_code: 201,
        failed: false,
        duration_ms: 45.9,
    );

    expect($payload->type())->toBe(TelemetryTypeEnum::OutgoingRequest);
    expectMatchesContract($payload->toArray(), $fixture);
});

it('keeps a null status code for a failed connection', function (): void {
    $payload = new OutgoingRequestEventPayload(fixtureMetadata(fixtureEvent('outgoing_request')), 'GET', 'down.example', 'https://down.example', null, true, 50.0);

    expect($payload->toArray())->toMatchArray(['status_code' => null, 'failed' => true]);
});
