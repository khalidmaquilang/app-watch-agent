<?php

declare(strict_types=1);

use AppWatch\Agent\Support\TraceContext;
use Illuminate\Console\Events\CommandStarting;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

beforeEach(function (): void {
    config(['app-watch.enabled' => true]);

    $this->trace_context = app(TraceContext::class);
    $this->trace_context->end();
});

it('starts a sampled trace for an artisan command', function (): void {
    config(['app-watch.sample_rates.request' => 0, 'app-watch.sample_rates.job' => 0]);

    event(new CommandStarting('orders:sync', new ArrayInput([]), new NullOutput));

    expect($this->trace_context->traceId())->not->toBeNull()
        ->and($this->trace_context->isSampled())->toBeTrue();
});

it('starts a new trace for every command', function (): void {
    event(new CommandStarting('orders:sync', new ArrayInput([]), new NullOutput));
    $first_trace_id = $this->trace_context->traceId();

    event(new CommandStarting('orders:sync', new ArrayInput([]), new NullOutput));

    expect($this->trace_context->traceId())->not->toBe($first_trace_id);
});

it('does not trace long-running commands', function (string $command): void {
    event(new CommandStarting($command, new ArrayInput([]), new NullOutput));

    expect($this->trace_context->traceId())->toBeNull();
})->with(['queue:work', 'queue:listen', 'schedule:run', 'schedule:work', 'horizon', 'octane:start']);

it('does not trace commands without an environment id', function (): void {
    config(['app-watch.environment_id' => null]);

    event(new CommandStarting('orders:sync', new ArrayInput([]), new NullOutput));

    expect($this->trace_context->traceId())->toBeNull();
});

it('does not trace the agent\'s own commands', function (string $command): void {
    event(new CommandStarting($command, new ArrayInput([]), new NullOutput));

    expect($this->trace_context->traceId())->toBeNull();
})->with(['app-watch:agent', 'app-watch:rollups', 'app-watch:partitions', 'app-watch:install', 'app-watch:future-command']);

it('still traces an app command after an agent command', function (): void {
    event(new CommandStarting('app-watch:rollups', new ArrayInput([]), new NullOutput));
    event(new CommandStarting('inspire', new ArrayInput([]), new NullOutput));

    expect($this->trace_context->traceId())->not->toBeNull();
});
