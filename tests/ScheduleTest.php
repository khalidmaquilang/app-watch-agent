<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * The agent's scheduled events (those running an `app-watch:` command), in registration order.
 *
 * @return list<Event>
 */
function agentScheduledEvents(): array
{
    return array_values(array_filter(
        app(Schedule::class)->events(),
        static fn (Event $event): bool => str_contains((string) $event->command, 'app-watch:'),
    ));
}

beforeEach(function (): void {
    config(['cache.default' => 'file']);
});

it('schedules the rollups and partitions upkeep without overlapping on one server', function (): void {
    $events = agentScheduledEvents();

    expect($events)->toHaveCount(4)
        ->and(array_map(static fn (Event $event): string => $event->expression, $events))
        ->toBe(['* * * * *', '2 * * * *', '15 0 * * *', '5 0 * * *']);

    foreach (['app-watch:rollups minute', 'app-watch:rollups hour', 'app-watch:rollups prune', 'app-watch:partitions'] as $index => $command) {
        expect((string) $events[$index]->command)->toEndWith($command)
            ->and($events[$index]->withoutOverlapping)->toBeTrue()
            ->and($events[$index]->onOneServer)->toBeTrue()
            ->and($events[$index]->runInBackground)->toBeFalse()
            ->and($events[$index]->description)->toStartWith('App Watch: ');
    }
});

it('schedules nothing while the agent is not configured', function (array $config): void {
    config($config);

    expect(agentScheduledEvents())->toBe([]);
})->with([
    'missing environment_id' => [['app-watch.environment_id' => null]],
    'disabled' => [['app-watch.enabled' => false]],
    'missing connection' => [['app-watch.connection' => 'missing']],
]);

it('schedules without onOneServer and warns once when the cache store has no shared locks', function (string $store): void {
    Log::spy();
    config(['cache.default' => $store]);

    $events = agentScheduledEvents();

    expect($events)->toHaveCount(4);

    foreach ($events as $event) {
        expect($event->onOneServer)->toBeFalse()
            ->and($event->withoutOverlapping)->toBeTrue();
    }

    Log::shouldHaveReceived('warning')->once()->withArgs(static fn (string $message): bool => str_contains($message, 'onOneServer()'));
})->with(['array', 'null']);

it('uses the schedule cache store when one is configured', function (): void {
    Log::spy();
    config(['cache.default' => 'array', 'cache.schedule_store' => 'file']);

    expect(agentScheduledEvents())->each(fn ($event) => $event->onOneServer->toBeTrue());

    Log::shouldNotHaveReceived('warning');
});

it('does not warn when the cache store supports locks', function (): void {
    Log::spy();

    agentScheduledEvents();

    Log::shouldNotHaveReceived('warning');
});

it('lists the four upkeep commands in schedule:list', function (): void {
    Artisan::call('schedule:list');

    $output = (string) preg_replace('/\s+/', ' ', Artisan::output());

    expect($output)
        ->toContain('* * * * * php artisan app-watch:rollups minute')
        ->toContain('2 * * * * php artisan app-watch:rollups hour')
        ->toContain('15 0 * * * php artisan app-watch:rollups prune')
        ->toContain('5 0 * * * php artisan app-watch:partitions');
});
