<?php

declare(strict_types=1);

namespace AppWatch\Agent;

use AppWatch\Agent\Support\IgnoreRules;
use AppWatch\Agent\Support\Sampler;
use AppWatch\Agent\Support\TraceContext;
use Illuminate\Support\ServiceProvider;

class AppWatchAgentServiceProvider extends ServiceProvider
{
    /**
     * Register the agent's services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/app-watch.php', 'app-watch');

        $this->app->singleton(Sampler::class);
        $this->app->singleton(IgnoreRules::class);
        $this->app->singleton(TraceContext::class);
        $this->app->singleton(Recorder::class);
    }

    /**
     * Bootstrap the agent's services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/app-watch.php' => config_path('app-watch.php'),
            ], 'app-watch-config');
        }
    }
}
