<?php

declare(strict_types=1);

use AppWatch\Agent\AppWatchAgentServiceProvider;

it('registers the agent service provider', function (): void {
    expect(app()->getProviders(AppWatchAgentServiceProvider::class))->not->toBeEmpty();
});
