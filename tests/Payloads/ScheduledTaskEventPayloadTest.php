<?php

declare(strict_types=1);

use AppWatch\Agent\Enums\ScheduledTaskKindEnum;
use AppWatch\Agent\Enums\ScheduledTaskOutcomeEnum;
use AppWatch\Agent\Enums\TelemetryTypeEnum;
use AppWatch\Agent\Payloads\ScheduledTaskEventPayload;

it('serializes the common fields first, then the scheduled task fields', function (): void {
    $metadata = fixtureMetadata(fixtureEvent('job'));

    $payload = new ScheduledTaskEventPayload(
        metadata: $metadata,
        task: 'emails:send --force',
        kind: ScheduledTaskKindEnum::Command,
        expression: '*/5 * * * *',
        timezone: 'Asia/Manila',
        outcome: ScheduledTaskOutcomeEnum::Failed,
        duration_ms: 42.5,
        exit_code: 3,
        without_overlapping: true,
        on_one_server: false,
    );

    expect($payload->type())->toBe(TelemetryTypeEnum::ScheduledTask)
        ->and($payload->traceId())->toBe($metadata->trace_id)
        ->and($payload->toArray())->toBe([
            'type' => 'scheduled_task',
            ...$metadata->toArray(),
            'task' => 'emails:send --force',
            'kind' => 'command',
            'expression' => '*/5 * * * *',
            'timezone' => 'Asia/Manila',
            'outcome' => 'failed',
            'duration_ms' => 42.5,
            'exit_code' => 3,
            'without_overlapping' => true,
            'on_one_server' => false,
        ]);
});

it('keeps the wire values of the scheduled task kinds and outcomes', function (): void {
    expect(array_column(ScheduledTaskKindEnum::cases(), 'value'))->toBe(['command', 'job', 'closure', 'exec'])
        ->and(array_column(ScheduledTaskOutcomeEnum::cases(), 'value'))->toBe(['succeeded', 'failed', 'skipped']);
});

it('serializes a skipped run without exit code or timezone', function (): void {
    $payload = new ScheduledTaskEventPayload(
        fixtureMetadata(fixtureEvent('job')), 'Closure', ScheduledTaskKindEnum::Closure, '* * * * *', null,
        ScheduledTaskOutcomeEnum::Skipped, 0.0, null, false, true,
    );

    expect($payload->toArray())->toMatchArray([
        'kind' => 'closure',
        'timezone' => null,
        'outcome' => 'skipped',
        'duration_ms' => 0.0,
        'exit_code' => null,
        'on_one_server' => true,
    ]);
});
