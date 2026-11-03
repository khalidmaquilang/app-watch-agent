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

it('never samples an ignored trace', function (): void {
    $context = app(TraceContext::class);

    $context->start(TelemetryTypeEnum::Request, ignored: true);

    expect($context->isActive())->toBeTrue()
        ->and($context->isSampled())->toBeFalse();
});

it('tracks how long the current trace has run', function (): void {
    $context = app(TraceContext::class);

    expect($context->startedAt())->toBeNull()
        ->and($context->elapsedMs())->toBeNull();

    $context->start(TelemetryTypeEnum::Request);
    usleep(1000);

    expect($context->startedAt())->toBeInt()
        ->and($context->elapsedMs())->toBeGreaterThanOrEqual(1.0);
});

it('restores the parent trace when a nested trace ends', function (): void {
    config(['app-watch.sample_rates.request' => 0]);
    $context = app(TraceContext::class);
    $parent_trace_id = $context->start(TelemetryTypeEnum::Request);

    $nested_trace_id = $context->startNested(TelemetryTypeEnum::Job);

    expect($nested_trace_id)->not->toBe($parent_trace_id)
        ->and($context->traceId())->toBe($nested_trace_id)
        ->and($context->isSampled())->toBeTrue();

    $context->endNested();

    expect($context->traceId())->toBe($parent_trace_id)
        ->and($context->isSampled())->toBeFalse();
});

it('ends with no trace when a nested trace started outside a trace ends', function (): void {
    $context = app(TraceContext::class);
    $context->end();

    $context->startNested(TelemetryTypeEnum::Job);
    $context->endNested();

    expect($context->traceId())->toBeNull();
});

it('forgets suspended traces when a new root trace starts', function (): void {
    $context = app(TraceContext::class);
    $context->start(TelemetryTypeEnum::Request);
    $context->startNested(TelemetryTypeEnum::Job);

    $root_trace_id = $context->start(TelemetryTypeEnum::Request);
    $context->endNested();

    expect($root_trace_id)->not->toBeNull()
        ->and($context->traceId())->toBeNull();
});

it('keeps at most the maximum nesting depth of parent traces', function (): void {
    $context = app(TraceContext::class);
    $root_trace_id = $context->start(TelemetryTypeEnum::Request);

    foreach (range(1, TraceContext::MAX_NESTING_DEPTH + 1) as $depth) {
        $context->startNested(TelemetryTypeEnum::Job);
    }

    foreach (range(1, TraceContext::MAX_NESTING_DEPTH + 1) as $depth) {
        $context->endNested();
    }

    expect($context->traceId())->toBeNull()->not->toBe($root_trace_id);
});
