<?php

declare(strict_types=1);

use AppWatch\Agent\Enums\TelemetryTypeEnum;
use AppWatch\Agent\Payloads\RequestEventPayload;

it('serializes to the v1 request event contract', function (): void {
    $fixture = fixtureEvent('request');

    $payload = new RequestEventPayload(
        metadata: fixtureMetadata($fixture),
        method: 'GET',
        route: 'orders/{order}',
        path: '/orders/42',
        status_code: 200,
        duration_ms: 87.42,
        memory_peak_bytes: 18874368,
    );

    expect($payload->type())->toBe(TelemetryTypeEnum::Request)
        ->and($payload->traceId())->toBe($fixture['trace_id']);
    expectMatchesContract($payload->toArray(), $fixture);
});

it('keeps nullable route and memory as null', function (): void {
    $fixture = fixtureEvent('request');

    $payload = new RequestEventPayload(fixtureMetadata($fixture), 'GET', null, '/', 404, 1.0, null);

    expect($payload->toArray())->toMatchArray(['route' => null, 'memory_peak_bytes' => null]);
});
