<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

afterEach(function (): void {
    File::delete(config_path('app-watch.php'));
});

it('merges the default config', function (): void {
    expect(config('app-watch.enabled'))->toBeTrue()
        ->and(config('app-watch.api_version'))->toBe('v1')
        ->and(config('app-watch.daemon'))->toBe(['host' => '127.0.0.1', 'port' => 2407, 'flush_events' => 500, 'flush_seconds' => 5, 'max_buffered_events' => 10000])
        ->and(config('app-watch.sample_rates.exception'))->toBe(1.0)
        ->and(config('app-watch.ignore.paths'))->toBe(['/up', '/telescope*', '/horizon*'])
        ->and(config('app-watch.log_level'))->toBe('warning')
        ->and(config('app-watch.deploy'))->toBeNull();
});

it('publishes config/app-watch.php with the app-watch-config tag', function (): void {
    File::delete(config_path('app-watch.php'));

    $this->artisan('vendor:publish', ['--tag' => 'app-watch-config'])->assertSuccessful();

    expect(File::exists(config_path('app-watch.php')))->toBeTrue();

    $published = require config_path('app-watch.php');

    expect(array_keys($published))->toBe([
        'enabled', 'token', 'monitor_url', 'api_version', 'daemon', 'sample_rates', 'ignore', 'log_level', 'deploy',
    ])
        ->and(array_keys($published['daemon']))->toBe(['host', 'port', 'flush_events', 'flush_seconds', 'max_buffered_events'])
        ->and(array_keys($published['sample_rates']))->toBe(['request', 'query', 'job', 'outgoing_request', 'cache', 'log', 'exception'])
        ->and(array_keys($published['ignore']))->toBe(['paths', 'jobs', 'queries']);
});
