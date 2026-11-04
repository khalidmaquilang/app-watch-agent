<?php

declare(strict_types=1);

namespace AppWatch\Agent;

use AppWatch\Agent\Console\AgentCommand;
use AppWatch\Agent\Console\InstallCommand;
use AppWatch\Agent\Console\PartitionsCommand;
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
use AppWatch\Agent\Maintenance\CreatePartitions;
use AppWatch\Agent\Maintenance\DropExpiredPartitions;
use AppWatch\Agent\Maintenance\PartitionedTables;
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
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
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
use Illuminate\Support\ServiceProvider;

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

            $this->commands([AgentCommand::class, InstallCommand::class, PartitionsCommand::class]);
        }

        if ($this->app->make(AgentConfiguration::class)->isConfigured()) {
            $this->registerTracing();
        }
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
     * request, cache, log and exception events, and flush each trace's events to the transport
     * when the request, job run or command ends.
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

        $register_reportable = function (mixed $handler): void {
            if ($handler instanceof Handler) {
                $handler->reportable([$this->app->make(RecordExceptionListener::class), 'handle']);
            }
        };

        // Only the framework's handler supports reportable callbacks; it may not be resolved yet.
        if ($this->app->resolved(ExceptionHandler::class)) {
            $register_reportable($this->app->make(ExceptionHandler::class));
        } else {
            $this->app->afterResolving(ExceptionHandler::class, $register_reportable);
        }
    }
}
