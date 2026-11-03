<?php

declare(strict_types=1);

use AppWatch\Agent\Enums\TelemetryTypeEnum;
use AppWatch\Agent\Payloads\QueryEventPayload;

it('serializes to the v1 query event contract', function (): void {
    $fixture = fixtureEvent('query');

    $payload = new QueryEventPayload(
        metadata: fixtureMetadata($fixture),
        connection: 'pgsql',
        sql: 'select * from "orders" where "id" = ? limit 1',
        sql_hash: '3e26a19500f48bd8a052683972c30b6587ea7aba',
        duration_ms: 1.27,
    );

    expect($payload->type())->toBe(TelemetryTypeEnum::Query);
    expectMatchesContract($payload->toArray(), $fixture);
});
