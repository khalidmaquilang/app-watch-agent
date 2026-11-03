<?php

declare(strict_types=1);

use AppWatch\Agent\Enums\TelemetryTypeEnum;
use AppWatch\Agent\Payloads\ExceptionEventPayload;
use AppWatch\Agent\Payloads\ExceptionTraceFrame;

it('serializes to the v1 exception event contract', function (): void {
    $fixture = fixtureEvent('exception');

    $payload = new ExceptionEventPayload(
        metadata: fixtureMetadata($fixture),
        class: 'RuntimeException',
        message: 'Mail transport unavailable',
        file: '/var/www/app/Mail/Transport.php',
        line: 88,
        trace: [
            new ExceptionTraceFrame('/var/www/app/Jobs/SendOrderConfirmationJob.php', 31, 'send', 'App\\Mail\\Transport'),
            new ExceptionTraceFrame(null, null, '{closure}', null),
        ],
        handled: false,
    );

    expect($payload->type())->toBe(TelemetryTypeEnum::Exception);
    expectMatchesContract($payload->toArray(), $fixture);
});

it('cuts the stack trace off at the contract maximum', function (): void {
    $frames = array_fill(0, ExceptionEventPayload::MAX_TRACE_FRAMES + 5, new ExceptionTraceFrame('/a.php', 1, 'f', null));

    $payload = new ExceptionEventPayload(fixtureMetadata(fixtureEvent('exception')), 'E', 'm', '/a.php', 1, $frames, true);

    expect($payload->toArray()['trace'])->toHaveCount(ExceptionEventPayload::MAX_TRACE_FRAMES);
});
