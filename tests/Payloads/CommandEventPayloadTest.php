<?php

declare(strict_types=1);

use AppWatch\Agent\Enums\TelemetryTypeEnum;
use AppWatch\Agent\Payloads\CommandEventPayload;

it('serializes the common fields first, then the command fields', function (): void {
    $metadata = fixtureMetadata(fixtureEvent('job'));

    $payload = new CommandEventPayload(
        metadata: $metadata,
        command: 'queue:retry',
        arguments: ['id' => '?', 'force' => true],
        exit_code: 0,
        duration_ms: 42.5,
    );

    expect($payload->type())->toBe(TelemetryTypeEnum::Command)
        ->and($payload->traceId())->toBe($metadata->trace_id)
        ->and($payload->toArray())->toBe([
            'type' => 'command',
            ...$metadata->toArray(),
            'command' => 'queue:retry',
            'arguments' => ['id' => '?', 'force' => true],
            'exit_code' => 0,
            'duration_ms' => 42.5,
            'failed' => false,
        ]);
});

it('is failed for any non-zero exit code', function (int $exit_code, bool $failed): void {
    $payload = new CommandEventPayload(fixtureMetadata(fixtureEvent('job')), 'orders:sync', [], $exit_code, 1.0);

    expect($payload->failed)->toBe($failed)
        ->and($payload->toArray()['failed'])->toBe($failed);
})->with([[0, false], [1, true], [2, true], [255, true]]);
