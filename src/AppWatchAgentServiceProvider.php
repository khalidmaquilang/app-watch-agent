<?php

declare(strict_types=1);

namespace AppWatch\Agent;

use AppWatch\Agent\Http\Middleware\StartRequestTraceMiddleware;
use AppWatch\Agent\Listeners\RecordJobListener;
use AppWatch\Agent\Listeners\RecordRequestListener;
use AppWatch\Agent\Listeners\StartCommandTraceListener;
use AppWatch\Agent\Support\IgnoreRules;
use AppWatch\Agent\Support\Sampler;
use AppWatch\Agent\Support\TraceContext;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
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
        $this->app->singleton(RecordJobListener::class);
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

        if ((bool) config('app-watch.enabled', true)) {
            $this->registerTracing();
        }
    }

    /**
     * Start traces for requests, jobs and commands, and record request and job events.
     */
    protected function registerTracing(): void
    {
        $prepend_middleware = static function (mixed $kernel): void {
            if ($kernel instanceof HttpKernel) {
                $kernel->prependMiddleware(StartRequestTraceMiddleware::class);
            }
        };

        // The HTTP kernel is already resolved while serving a request; in the console it may never be.
        if ($this->app->resolved(HttpKernelContract::class)) {
            $prepend_middleware($this->app->make(HttpKernelContract::class));
        } else {
            $this->app->afterResolving(HttpKernelContract::class, $prepend_middleware);
        }

        $events = $this->app->make(Dispatcher::class);

        $events->listen(RequestHandled::class, RecordRequestListener::class);
        $events->listen(CommandStarting::class, StartCommandTraceListener::class);
        $events->listen(JobProcessing::class, [RecordJobListener::class, 'handleJobProcessing']);
        $events->listen(JobProcessed::class, [RecordJobListener::class, 'handleJobProcessed']);
        $events->listen(JobFailed::class, [RecordJobListener::class, 'handleJobFailed']);
        $events->listen(JobReleasedAfterException::class, [RecordJobListener::class, 'handleJobReleasedAfterException']);
        $events->listen(JobAttempted::class, [RecordJobListener::class, 'handleJobAttempted']);
    }
}
