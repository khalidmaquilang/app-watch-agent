<?php

declare(strict_types=1);

namespace AppWatch\Agent;

use AppWatch\Agent\Console\AgentCommand;
use AppWatch\Agent\Console\InstallCommand;
use AppWatch\Agent\Console\PartitionsCommand;
use AppWatch\Agent\Console\RollupsCommand;
use AppWatch\Agent\Http\Middleware\StartRequestTraceMiddleware;
use AppWatch\Agent\Listeners\FlushTraceListener;
use AppWatch\Agent\Listeners\RecordCacheListener;
use AppWatch\Agent\Listeners\RecordExceptionListener;
use AppWatch\Agent\Listeners\RecordJobListener;
use AppWatch\Agent\Listeners\RecordLogListener;
use AppWatch\Agent\Listeners\RecordOutgoingRequestListener;
use AppWatch\Agent\Listeners\RecordQueryListener;
use AppWatch\Agent\Listeners\RecordRequestListener;
use AppWatch\Agent\Listeners\StartCommandTraceListener;
use AppWatch\Agent\Maintenance\BuildHourRollups;
use AppWatch\Agent\Maintenance\BuildMinuteRollups;
use AppWatch\Agent\Maintenance\BuildRollupsForRange;
use AppWatch\Agent\Maintenance\CreatePartitions;
use AppWatch\Agent\Maintenance\DropExpiredPartitions;
use AppWatch\Agent\Maintenance\PartitionedTables;
use AppWatch\Agent\Maintenance\PruneRollups;
use AppWatch\Agent\Storage\BatchWriter;
use AppWatch\Agent\Storage\CacheEventWriter;
use AppWatch\Agent\Storage\EventValidator;
use AppWatch\Agent\Storage\ExceptionEventWriter;
use AppWatch\Agent\Storage\FingerprintException;
use AppWatch\Agent\Storage\JobEventWriter;
use AppWatch\Agent\Storage\LogEventWriter;
use AppWatch\Agent\Storage\NormalizeExceptionMessage;
use AppWatch\Agent\Storage\OutgoingRequestEventWriter;
use AppWatch\Agent\Storage\PartitionDdl;
use AppWatch\Agent\Storage\QueryEventWriter;
use AppWatch\Agent\Storage\RequestEventWriter;
use AppWatch\Agent\Storage\UpsertExceptionGroup;
use AppWatch\Agent\Support\AgentConfiguration;
use AppWatch\Agent\Support\IgnoreRules;
use AppWatch\Agent\Support\LogContextSanitizer;
use AppWatch\Agent\Support\Sampler;
use AppWatch\Agent\Support\SqlNormalizer;
use AppWatch\Agent\Support\TraceContext;
use AppWatch\Agent\Transport\DaemonTransport;
use AppWatch\Agent\Transport\NullTransport;
use AppWatch\Agent\Transport\Transport;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\NullStore;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppWatchAgentServiceProvider extends ServiceProvider
{
    /**
     * Register the agent's services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/app-watch.php', 'app-watch');

        $this->registerTelemetryConnection();

        $this->app->singleton(AgentConfiguration::class);
        $this->app->singleton(Sampler::class);
        $this->app->singleton(IgnoreRules::class);
        $this->app->singleton(TraceContext::class);
        $this->app->singleton(Recorder::class);
        $this->app->singleton(SqlNormalizer::class);
        $this->app->singleton(PartitionDdl::class);
        $this->app->singleton(PartitionedTables::class);
        $this->app->singleton(CreatePartitions::class);
        $this->app->singleton(DropExpiredPartitions::class);
        $this->app->singleton(BuildRollupsForRange::class);
        $this->app->singleton(BuildMinuteRollups::class);
        $this->app->singleton(BuildHourRollups::class);
        $this->app->singleton(PruneRollups::class);
        $this->app->singleton(NormalizeExceptionMessage::class);
        $this->app->singleton(FingerprintException::class);
        $this->app->singleton(UpsertExceptionGroup::class);
        $this->app->singleton(EventValidator::class);
        $this->app->singleton(RequestEventWriter::class);
        $this->app->singleton(QueryEventWriter::class);
        $this->app->singleton(JobEventWriter::class);
        $this->app->singleton(OutgoingRequestEventWriter::class);
        $this->app->singleton(CacheEventWriter::class);
        $this->app->singleton(LogEventWriter::class);
        $this->app->singleton(ExceptionEventWriter::class);
        $this->app->singleton(BatchWriter::class);
        $this->app->singleton(RecordJobListener::class);
        $this->app->singleton(RecordQueryListener::class);
        $this->app->singleton(RecordOutgoingRequestListener::class);
        $this->app->singleton(LogContextSanitizer::class);
        $this->app->singleton(RecordCacheListener::class);
        $this->app->singleton(RecordLogListener::class);
        $this->app->singleton(RecordExceptionListener::class);
        $this->app->singleton(DaemonTransport::class);
        $this->app->singleton(
            Transport::class,
            static fn (Application $app): Transport => $app->make(Recorder::class)->isEnabled()
                ? $app->make(DaemonTransport::class)
                : new NullTransport,
        );

        $this->registerExceptionRecording();
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

            $this->commands([AgentCommand::class, InstallCommand::class, PartitionsCommand::class, RollupsCommand::class]);

            // The schedule is resolved lazily (schedule:run / schedule:list), never through the HTTP kernel.
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $this->registerSchedule($schedule);
            });
        }

        if ($this->app->make(AgentConfiguration::class)->isConfigured()) {
            $this->registerTracing();
        }
    }

    /**
     * Schedule the agent's upkeep (minute/hour rollups, rollup pruning and daily partitions), each
     * without overlapping and on one server. Nothing is scheduled unless the agent is configured.
     * When the schedule's cache store cannot hold locks shared across servers, a warning is logged
     * and the events are scheduled without `onOneServer()`, so a single-server app keeps working.
     */
    protected function registerSchedule(Schedule $schedule): void
    {
        if (! $this->app->make(AgentConfiguration::class)->isConfigured()) {
            return;
        }

        $on_one_server = $this->scheduleCacheSupportsLocks();

        if (! $on_one_server) {
            Log::warning('App Watch: the schedule cache store does not support shared atomic locks, so the agent upkeep is scheduled without onOneServer(). Multi-server apps need a lock-capable cache store (redis, database, memcached, dynamodb or file).');
        }

        $events = [
            $schedule->command('app-watch:rollups minute')->everyMinute()->description('App Watch: build minute rollups'),
            $schedule->command('app-watch:rollups hour')->hourlyAt(2)->description('App Watch: build hour rollups'),
            $schedule->command('app-watch:rollups prune')->dailyAt('00:15')->description('App Watch: prune expired rollups'),
            $schedule->command('app-watch:partitions')->dailyAt('00:05')->description('App Watch: create and drop daily partitions'),
        ];

        foreach ($events as $event) {
            $event->withoutOverlapping();

            if ($on_one_server) {
                $event->onOneServer();
            }
        }
    }

    /**
     * Whether the cache store the scheduler uses (`cache.schedule_store`, else the default store)
     * provides atomic locks shared across servers. The array and null stores implement locks only
     * within one process, so they do not count. Never throws.
     */
    protected function scheduleCacheSupportsLocks(): bool
    {
        try {
            $store_name = $this->app->make('config')->get('cache.schedule_store');
            $store = $this->app->make(CacheFactory::class)
                ->store(is_string($store_name) && $store_name !== '' ? $store_name : null)
                ->getStore();
        } catch (Throwable) {
            return false;
        }

        return $store instanceof LockProvider
            && ! $store instanceof ArrayStore
            && ! $store instanceof NullStore;
    }

    /**
     * Register the telemetry connection (`app-watch.connection`) as a pgsql connection from the
     * `app-watch.database` settings, unless the host app already defines a connection with that
     * name (it then wins untouched) or no database name is set.
     */
    protected function registerTelemetryConnection(): void
    {
        $config = $this->app->make('config');
        $connection = $config->get('app-watch.connection');
        $database = $config->get('app-watch.database');

        if (! is_string($connection) || $connection === '' || $config->get('database.connections.'.$connection) !== null) {
            return;
        }

        if (! is_array($database) || ! is_string($database['database'] ?? null) || $database['database'] === '') {
            return;
        }

        $config->set('database.connections.'.$connection, [
            'driver' => 'pgsql',
            'host' => $database['host'] ?? null,
            'port' => $database['port'] ?? 5432,
            'database' => $database['database'],
            'username' => $database['username'] ?? null,
            'password' => $database['password'] ?? null,
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => $database['sslmode'] ?? 'require',
        ]);
    }

    /**
     * Start traces for requests, jobs and commands, and record request, job, query, outgoing
     * request, cache and log events, and flush each trace's events to the transport when the
     * request, job run or command ends.
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
        // Flush the run's trace once its job event is recorded, and again (before the trace ends)
        // for anything recorded after that, e.g. by JobFailed listeners.
        $events->listen([JobProcessed::class, JobFailed::class, JobReleasedAfterException::class], FlushTraceListener::class);
        $events->listen(JobAttempted::class, FlushTraceListener::class);
        $events->listen(JobAttempted::class, [RecordJobListener::class, 'handleJobAttempted']);
        $events->listen(QueryExecuted::class, RecordQueryListener::class);
        $events->listen(RequestSending::class, [RecordOutgoingRequestListener::class, 'handleRequestSending']);
        $events->listen(ResponseReceived::class, [RecordOutgoingRequestListener::class, 'handleResponseReceived']);
        $events->listen(ConnectionFailed::class, [RecordOutgoingRequestListener::class, 'handleConnectionFailed']);
        $events->listen([CacheHit::class, CacheMissed::class, KeyWritten::class, KeyForgotten::class], RecordCacheListener::class);
        $events->listen(MessageLogged::class, RecordLogListener::class);
        $events->listen(CommandFinished::class, FlushTraceListener::class);

        $this->app->terminating(function (): void {
            $this->app->make(FlushTraceListener::class)->handle();
        });
    }

    /**
     * Record the exceptions the app's handler reports. This happens while providers register, not
     * at boot, because a provider may then wrap the handler in a class that has no reportable
     * callbacks (Collision does in the console), which would hide it from the agent. The listener
     * stays silent until the agent is configured.
     */
    protected function registerExceptionRecording(): void
    {
        $register_reportable = function (mixed $handler): void {
            if ($handler instanceof Handler) {
                $handler->reportable([$this->app->make(RecordExceptionListener::class), 'handle']);
            }
        };

        // shortcut: a wrapper registered before the agent still hides the handler; package providers load alphabetically, so Collision comes after it.
        if ($this->app->resolved(ExceptionHandler::class)) {
            $register_reportable($this->app->make(ExceptionHandler::class));
        } else {
            $this->app->afterResolving(ExceptionHandler::class, $register_reportable);
        }
    }
}
