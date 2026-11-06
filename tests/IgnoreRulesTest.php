<?php

declare(strict_types=1);

use AppWatch\Agent\Enums\LogLevelEnum;
use AppWatch\Agent\Enums\ScheduledTaskKindEnum;
use AppWatch\Agent\Enums\ScheduledTaskOutcomeEnum;
use AppWatch\Agent\Payloads\CommandEventPayload;
use AppWatch\Agent\Payloads\EventMetadata;
use AppWatch\Agent\Payloads\ScheduledTaskEventPayload;
use AppWatch\Agent\Support\IgnoreRules;

beforeEach(function (): void {
    $this->rules = app(IgnoreRules::class);
});

it('ignores the default /up, /telescope* and /horizon* paths', function (string $path): void {
    expect($this->rules->shouldIgnorePath($path))->toBeTrue()
        ->and($this->rules->shouldIgnore(requestEvent('t', $path)))->toBeTrue();
})->with(['/up', 'up', '/telescope', '/telescope/requests', '/horizon/dashboard']);

it('records other paths', function (): void {
    expect($this->rules->shouldIgnorePath('/orders/42'))->toBeFalse()
        ->and($this->rules->shouldIgnorePath('/upload'))->toBeFalse()
        ->and($this->rules->shouldIgnore(requestEvent('t', '/orders/42')))->toBeFalse();
});

it('ignores configured job classes', function (): void {
    config(['app-watch.ignore.jobs' => ['App\\Jobs\\PruneJob', 'App\\Jobs\\Internal\\*']]);

    expect($this->rules->shouldIgnore(jobEvent('t', 'App\\Jobs\\PruneJob')))->toBeTrue()
        ->and($this->rules->shouldIgnoreJob('\\App\\Jobs\\PruneJob'))->toBeTrue()
        ->and($this->rules->shouldIgnoreJob('App\\Jobs\\Internal\\SyncJob'))->toBeTrue()
        ->and($this->rules->shouldIgnore(jobEvent('t', 'App\\Jobs\\SendOrderConfirmationJob')))->toBeFalse();
});

it('ignores queries matching an ignore regex', function (): void {
    config(['app-watch.ignore.queries' => ['/^select \* from "sessions"/i']]);

    expect($this->rules->shouldIgnore(queryEvent('t', 'select * from "sessions" where "id" = ?')))->toBeTrue()
        ->and($this->rules->shouldIgnore(queryEvent('t', 'select * from "orders"')))->toBeFalse();
});

it('never matches an invalid query regex', function (): void {
    config(['app-watch.ignore.queries' => ['not a regex', 123]]);

    expect($this->rules->shouldIgnoreQuery('select 1'))->toBeFalse();
});

it('ignores log messages below the configured level', function (): void {
    config(['app-watch.log_level' => 'error']);

    expect($this->rules->shouldIgnore(logEvent('t', LogLevelEnum::Warning)))->toBeTrue()
        ->and($this->rules->shouldIgnore(logEvent('t', LogLevelEnum::Error)))->toBeFalse()
        ->and($this->rules->shouldIgnore(logEvent('t', LogLevelEnum::Critical)))->toBeFalse();
});

it('falls back to warning for an unknown log level', function (): void {
    config(['app-watch.log_level' => 'loud']);

    expect($this->rules->shouldIgnoreLogLevel(LogLevelEnum::Notice))->toBeTrue()
        ->and($this->rules->shouldIgnoreLogLevel(LogLevelEnum::Warning))->toBeFalse();
});

it('never ignores exceptions', function (): void {
    expect($this->rules->shouldIgnore(exceptionEvent('t')))->toBeFalse();
});

it('ignores the default long-running and agent commands', function (string $name): void {
    expect($this->rules->shouldIgnoreCommand($name))->toBeTrue();
})->with(['app-watch:agent', 'queue:work', 'queue:listen', 'schedule:run', 'schedule:work', 'horizon', 'horizon:supervisor', 'octane:start']);

it('records other commands by default, the scheduler\'s children included', function (string $name): void {
    expect($this->rules->shouldIgnoreCommand($name))->toBeFalse();
})->with(['orders:sync', 'queue:retry', 'list', 'inspire']);

it('ignores configured command globs and command events', function (): void {
    config(['app-watch.ignore.commands' => ['orders:*', '', 42]]);

    $event = new CommandEventPayload(EventMetadata::capture('t'), 'orders:sync', [], 0, 1.0);

    expect($this->rules->shouldIgnoreCommand('orders:sync'))->toBeTrue()
        ->and($this->rules->shouldIgnore($event))->toBeTrue()
        ->and($this->rules->shouldIgnoreCommand('queue:work'))->toBeFalse();
});

it('ignores no command when the list is empty or not a list', function (mixed $commands): void {
    config(['app-watch.ignore.commands' => $commands]);

    expect($this->rules->shouldIgnoreCommand('app-watch:agent'))->toBeFalse();
})->with([[[]], [null], ['app-watch:*']]);

it('ignores the agent\'s own app-watch:* scheduled tasks by default and records the rest', function (): void {
    expect($this->rules->shouldIgnoreScheduledTask('app-watch:rollups minute'))->toBeTrue()
        ->and($this->rules->shouldIgnoreScheduledTask('app-watch:partitions'))->toBeTrue()
        ->and($this->rules->shouldIgnoreScheduledTask('emails:send --force'))->toBeFalse()
        ->and($this->rules->shouldIgnoreScheduledTask('Closure'))->toBeFalse();
});

it('ignores configured scheduled task globs and scheduled task events', function (): void {
    config(['app-watch.ignore.scheduled_tasks' => ['emails:*', 'App\\Jobs\\*', '', 42]]);

    $event = new ScheduledTaskEventPayload(
        EventMetadata::capture('t'), 'emails:send --force', ScheduledTaskKindEnum::Command, '* * * * *', null,
        ScheduledTaskOutcomeEnum::Succeeded, 1.0, 0, false, false,
    );

    expect($this->rules->shouldIgnoreScheduledTask('emails:send --force'))->toBeTrue()
        ->and($this->rules->shouldIgnore($event))->toBeTrue()
        ->and($this->rules->shouldIgnoreScheduledTask('App\\Jobs\\PruneJob'))->toBeTrue()
        ->and($this->rules->shouldIgnoreScheduledTask('app-watch:rollups minute'))->toBeFalse();
});

it('ignores no scheduled task when the list is empty or not a list', function (mixed $tasks): void {
    config(['app-watch.ignore.scheduled_tasks' => $tasks]);

    expect($this->rules->shouldIgnoreScheduledTask('app-watch:rollups minute'))->toBeFalse();
})->with([[[]], [null], ['app-watch:*']]);
