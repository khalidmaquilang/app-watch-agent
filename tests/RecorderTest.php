<?php

declare(strict_types=1);

use AppWatch\Agent\Enums\TelemetryTypeEnum;
use AppWatch\Agent\Payloads\EventPayload;
use AppWatch\Agent\Recorder;
use AppWatch\Agent\Support\Sampler;
use AppWatch\Agent\Support\TraceContext;

beforeEach(function (): void {
    config(['app-watch.enabled' => true, 'app-watch.token' => 'environment-token']);

    $this->recorder = app(Recorder::class);
    $this->trace_context = app(TraceContext::class);
});

it('is a singleton', function (): void {
    expect(app(Recorder::class))->toBe($this->recorder);
});

it('buffers events and flushes only the given trace', function (): void {
    $this->recorder->record(requestEvent('trace-a'));
    $this->recorder->record(queryEvent('trace-a'));
    $this->recorder->record(queryEvent('trace-b'));

    $events = $this->recorder->flush('trace-a');

    expect($events)->toHaveCount(2)
        ->and(array_map(fn (EventPayload $event): string => $event->traceId(), $events))->toBe(['trace-a', 'trace-a'])
        ->and($this->recorder->flush('trace-a'))->toBe([])
        ->and($this->recorder->flush('trace-b'))->toHaveCount(1);
});

it('returns nothing for an unknown trace', function (): void {
    expect($this->recorder->flush('missing'))->toBe([]);
});

it('stores nothing when disabled', function (): void {
    config(['app-watch.enabled' => false]);

    $this->recorder->record(requestEvent('trace-a'));

    expect($this->recorder->isEnabled())->toBeFalse()
        ->and($this->recorder->flush('trace-a'))->toBe([]);
});

it('stores nothing without a token', function (mixed $token): void {
    config(['app-watch.token' => $token]);

    $this->recorder->record(requestEvent('trace-a'));

    expect($this->recorder->flush('trace-a'))->toBe([]);
})->with(['missing' => [null], 'empty' => ['']]);

it('records no event of an unsampled request trace', function (): void {
    config(['app-watch.sample_rates.request' => 0]);
    $trace_id = (string) $this->trace_context->start(TelemetryTypeEnum::Request);

    $this->recorder->record(requestEvent($trace_id));
    $this->recorder->record(queryEvent($trace_id));
    $this->recorder->record(logEvent($trace_id));

    expect($this->recorder->flush($trace_id))->toBe([]);
});

it('still records exceptions of an unsampled trace', function (): void {
    config(['app-watch.sample_rates.request' => 0]);
    $trace_id = (string) $this->trace_context->start(TelemetryTypeEnum::Request);

    $this->recorder->record(exceptionEvent($trace_id));

    expect($this->recorder->flush($trace_id))->toHaveCount(1);
});

it('records a sampled trace completely', function (): void {
    $trace_id = (string) $this->trace_context->start(TelemetryTypeEnum::Request);

    $this->recorder->record(requestEvent($trace_id));
    $this->recorder->record(queryEvent($trace_id));

    expect($this->recorder->flush($trace_id))->toHaveCount(2);
});

it('samples a seeded request rate of 0.5 deterministically per trace', function (): void {
    config(['app-watch.sample_rates.request' => 0.5]);

    $run = function (): array {
        app()->instance(Sampler::class, Sampler::seeded(config(), 7));
        app()->forgetInstance(TraceContext::class);
        app()->forgetInstance(Recorder::class);
        $trace_context = app(TraceContext::class);
        $recorder = app(Recorder::class);

        return array_map(function () use ($trace_context, $recorder): int {
            $trace_id = (string) $trace_context->start(TelemetryTypeEnum::Request);
            $recorder->record(requestEvent($trace_id));
            $recorder->record(queryEvent($trace_id));

            return count($recorder->flush($trace_id));
        }, range(1, 20));
    };

    $first_run = $run();

    expect($run())->toBe($first_run)
        ->and(array_unique($first_run))->toEqualCanonicalizing([0, 2]);
});

it('samples other types per event', function (): void {
    config(['app-watch.sample_rates.query' => 0]);
    $trace_id = (string) $this->trace_context->start(TelemetryTypeEnum::Request);

    $this->recorder->record(requestEvent($trace_id));
    $this->recorder->record(queryEvent($trace_id));

    expect(array_map(fn (EventPayload $event): TelemetryTypeEnum => $event->type(), $this->recorder->flush($trace_id)))
        ->toBe([TelemetryTypeEnum::Request]);
});

it('skips ignored events', function (): void {
    config(['app-watch.ignore.jobs' => ['App\\Jobs\\PruneJob'], 'app-watch.ignore.queries' => ['/sessions/']]);

    $this->recorder->record(requestEvent('trace-a', '/up'));
    $this->recorder->record(jobEvent('trace-a', 'App\\Jobs\\PruneJob'));
    $this->recorder->record(queryEvent('trace-a', 'select * from "sessions"'));
    $this->recorder->record(queryEvent('trace-a', 'select * from "orders"'));

    expect($this->recorder->flush('trace-a'))->toHaveCount(1);
});

it('drops and counts events over the per-trace cap without throwing', function (): void {
    foreach (range(1, Recorder::MAX_EVENTS_PER_TRACE + 3) as $ignored) {
        $this->recorder->record(queryEvent('trace-a'));
    }
    $this->recorder->record(queryEvent('trace-b'));

    expect($this->recorder->droppedCount('trace-a'))->toBe(3)
        ->and($this->recorder->droppedCount('trace-b'))->toBe(0)
        ->and($this->recorder->flush('trace-a'))->toHaveCount(Recorder::MAX_EVENTS_PER_TRACE)
        ->and($this->recorder->droppedCount('trace-a'))->toBe(0)
        ->and($this->recorder->flush('trace-b'))->toHaveCount(1);
});

it('never throws into the host app', function (): void {
    $broken_event = new class implements EventPayload
    {
        public function type(): TelemetryTypeEnum
        {
            throw new RuntimeException('broken');
        }

        public function traceId(): string
        {
            throw new RuntimeException('broken');
        }

        public function toArray(): array
        {
            return [];
        }
    };

    $this->recorder->record($broken_event);

    expect($this->recorder->flush('trace-a'))->toBe([]);
});

it('forgets every buffer on reset', function (): void {
    $this->recorder->record(queryEvent('trace-a'));
    $this->recorder->record(queryEvent('trace-b'));

    $this->recorder->reset();

    expect($this->recorder->flush('trace-a'))->toBe([])
        ->and($this->recorder->flush('trace-b'))->toBe([]);
});
