<?php

declare(strict_types=1);

use AppWatch\Agent\Enums\TelemetryTypeEnum;
use AppWatch\Agent\Support\TraceContext;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

it('starts a trace with a uuid v7 id', function (): void {
    $context = app(TraceContext::class);

    $trace_id = $context->start(TelemetryTypeEnum::Request);

    expect($trace_id)->not->toBeNull()
        ->and(Str::isUuid($trace_id))->toBeTrue()
        ->and(Uuid::fromString($trace_id)->getFields()->getVersion())->toBe(7)
        ->and($context->traceId())->toBe($trace_id)
        ->and($context->isActive())->toBeTrue()
        ->and($context->isSampled())->toBeTrue();
});

it('gives every trace a new id', function (): void {
    $context = app(TraceContext::class);

    expect($context->start(TelemetryTypeEnum::Request))->not->toBe($context->start(TelemetryTypeEnum::Job));
});

it('clears the trace on end', function (): void {
    $context = app(TraceContext::class);
    $context->start(TelemetryTypeEnum::Job);

    $context->end();

    expect($context->traceId())->toBeNull()
        ->and($context->isActive())->toBeFalse()
        ->and($context->isSampled())->toBeTrue();
});

it('samples request and job traces once with their rate', function (TelemetryTypeEnum $type, string $rate_key): void {
    config(['app-watch.sample_rates.'.$rate_key => 0]);
    $context = app(TraceContext::class);

    $context->start($type);

    expect($context->isSampled())->toBeFalse();
})->with([
    'request' => [TelemetryTypeEnum::Request, 'request'],
    'job' => [TelemetryTypeEnum::Job, 'job'],
]);

it('always samples a console command trace', function (): void {
    config(['app-watch.sample_rates.request' => 0, 'app-watch.sample_rates.job' => 0]);
    $context = app(TraceContext::class);

    $context->start();

    expect($context->isSampled())->toBeTrue();
});

it('resets the sampling decision when a new trace starts', function (): void {
    config(['app-watch.sample_rates.request' => 0]);
    $context = app(TraceContext::class);
    $context->start(TelemetryTypeEnum::Request);

    $context->start(TelemetryTypeEnum::Job);

    expect($context->isSampled())->toBeTrue();
});
