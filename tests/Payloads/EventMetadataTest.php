<?php

declare(strict_types=1);

use AppWatch\Agent\Payloads\EventMetadata;

it('captures the host name and configured deploy', function (): void {
    config(['app-watch.deploy' => 'v1.4.2']);

    $metadata = EventMetadata::capture('9b2f6a2e-3c1d-4f5a-8b7e-1a2b3c4d5e6f');

    expect($metadata->host)->toBe(gethostname())
        ->and($metadata->deploy)->toBe('v1.4.2')
        ->and($metadata->trace_id)->toBe('9b2f6a2e-3c1d-4f5a-8b7e-1a2b3c4d5e6f');
});

it('uses a null deploy when none is configured', function (): void {
    config(['app-watch.deploy' => '']);

    expect(EventMetadata::capture('t')->deploy)->toBeNull();
});

it('formats recorded_at as ISO-8601 with microseconds in UTC', function (): void {
    $recorded_at = new DateTimeImmutable('2026-11-03 13:41:03.123456', new DateTimeZone('Asia/Manila'));

    $metadata = EventMetadata::capture('t', $recorded_at);

    expect($metadata->toArray()['recorded_at'])->toBe('2026-11-03T05:41:03.123456+00:00');
});
