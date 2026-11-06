<?php

declare(strict_types=1);

use AppWatch\Agent\Support\TraceContext;
use Illuminate\Console\Events\CommandFinished;
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
    $input = new ArrayInput([]);
    event(new CommandStarting('orders:sync', $input, new NullOutput));
    $first_trace_id = $this->trace_context->traceId();
    event(new CommandFinished('orders:sync', $input, new NullOutput, 0));

    event(new CommandStarting('orders:sync', new ArrayInput([]), new NullOutput));

    expect($this->trace_context->traceId())->not->toBe($first_trace_id);
});

it('keeps the running command\'s trace for a command started inside it', function (): void {
    event(new CommandStarting('orders:sync', new ArrayInput([]), new NullOutput));
    $outer_trace_id = $this->trace_context->traceId();

    event(new CommandStarting('orders:prune', new ArrayInput([]), new NullOutput));

    expect($this->trace_context->traceId())->toBe($outer_trace_id);
});

it('takes the ignored commands from app-watch.ignore.commands', function (): void {
    config(['app-watch.ignore.commands' => ['orders:*']]);

    event(new CommandStarting('orders:sync', new ArrayInput([]), new NullOutput));
    expect($this->trace_context->traceId())->toBeNull();

    event(new CommandStarting('horizon:fixture', new ArrayInput([]), new NullOutput));
    expect($this->trace_context->traceId())->not->toBeNull();
});

it('traces every command once the ignore list is emptied', function (): void {
    config(['app-watch.ignore.commands' => []]);

    event(new CommandStarting('queue:work', new ArrayInput([]), new NullOutput));

    expect($this->trace_context->traceId())->not->toBeNull();
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
