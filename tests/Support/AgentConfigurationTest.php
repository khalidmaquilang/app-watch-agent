<?php

declare(strict_types=1);

use AppWatch\Agent\Support\AgentConfiguration;

it('is configured while enabled with a positive environment id and an existing connection', function (mixed $environment_id): void {
    config(['app-watch.environment_id' => $environment_id]);

    expect(app(AgentConfiguration::class)->isConfigured())->toBeTrue();
})->with([
    'an integer' => [1],
    'a large integer' => [9007199254740991],
    'a digit string from a published config' => ['42'],
]);

it('is not configured', function (array $config): void {
    config($config);

    expect(app(AgentConfiguration::class)->isConfigured())->toBeFalse();
})->with([
    'disabled' => [['app-watch.enabled' => false]],
    'without an environment id' => [['app-watch.environment_id' => null]],
    'with environment id 0' => [['app-watch.environment_id' => 0]],
    'with a negative environment id' => [['app-watch.environment_id' => -3]],
    'with a non-numeric environment id' => [['app-watch.environment_id' => 'abc']],
    'with a decimal environment id' => [['app-watch.environment_id' => '1.5']],
    'with a boolean environment id' => [['app-watch.environment_id' => true]],
    'with a missing connection' => [['app-watch.connection' => 'missing']],
    'with an empty connection name' => [['app-watch.connection' => '']],
    'with a non-string connection name' => [['app-watch.connection' => ['app_watch']]],
    'with the connection removed' => [['database.connections.app_watch' => null]],
]);

it('uses a connection the host app already defines', function (): void {
    config(['app-watch.connection' => 'pgsql']);

    expect(app(AgentConfiguration::class)->isConfigured())->toBeTrue();
});

it('returns the environment id only when it is a positive integer', function (mixed $environment_id, ?int $expected): void {
    config(['app-watch.environment_id' => $environment_id]);

    expect(app(AgentConfiguration::class)->environmentId())->toBe($expected);
})->with([
    [7, 7],
    ['7', 7],
    [null, null],
    [0, null],
    ['abc', null],
    [7.0, null],
]);

it('is a singleton', function (): void {
    expect(app(AgentConfiguration::class))->toBe(app(AgentConfiguration::class));
});
