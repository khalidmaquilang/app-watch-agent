<?php

declare(strict_types=1);

use AppWatch\Agent\Daemon\Batcher;
use AppWatch\Agent\Daemon\Daemon;
use AppWatch\Agent\Enums\CacheOperationEnum;
use AppWatch\Agent\Enums\JobStatusEnum;
use AppWatch\Agent\Enums\LogLevelEnum;
use AppWatch\Agent\Payloads\CacheEventPayload;
use AppWatch\Agent\Payloads\CommandEventPayload;
use AppWatch\Agent\Payloads\EventMetadata;
use AppWatch\Agent\Payloads\ExceptionEventPayload;
use AppWatch\Agent\Payloads\JobEventPayload;
use AppWatch\Agent\Payloads\LogEventPayload;
use AppWatch\Agent\Payloads\OutgoingRequestEventPayload;
use AppWatch\Agent\Payloads\QueryEventPayload;
use AppWatch\Agent\Payloads\RequestEventPayload;
use AppWatch\Agent\Recorder;
use AppWatch\Agent\Storage\Schema;
use AppWatch\Agent\Support\TraceContext;
use AppWatch\Agent\Tests\Fixtures\Commands\ReportCommand;
use AppWatch\Agent\Tests\Fixtures\Exceptions\CheckoutFailedException;
use AppWatch\Agent\Tests\Fixtures\Jobs\TracedJob;
use AppWatch\Agent\Tests\Support\TelemetryRows;
use AppWatch\Agent\Transport\DaemonTransport;
use AppWatch\Agent\Transport\FakeTransport;
use AppWatch\Agent\Transport\Transport;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Psr\Log\LogLevel;

/**
 * The raw tables one traced request to /checkout writes, with the rows it adds to each.
 */
const CHECKOUT_ROWS = [
    'requests' => 1,
    'queries' => 3,
    'cache_events' => 2,
    'logs' => 1,
    'outgoing_requests' => 1,
    'exception_occurrences' => 1,
];

beforeEach(function (): void {
    config([
        'app-watch.enabled' => true,
        'app-watch.environment_id' => 42,
        'app-watch.daemon.flush_events' => 1,
        'cache.default' => 'array',
        'logging.default' => 'null',
        'queue.default' => 'sync',
    ]);

    TracedJob::$trace_ids = [];
    TracedJob::$during = null;

    // Registered before the install command builds the Artisan application, which then includes it.
    Artisan::command('orders:sync', function (): void {
        TracedJob::dispatch();
    });

    // Install before the real transport is bound (agent commands are never traced, so nothing is sent).
    installTelemetrySchema();

    $this->batcher = app(Batcher::class);
    $this->daemon = app()->make(Daemon::class, ['batcher' => $this->batcher]);
    $this->daemon->listen('127.0.0.1', 0);

    config([
        'app-watch.daemon.host' => '127.0.0.1',
        'app-watch.daemon.port' => (int) substr((string) strrchr((string) $this->daemon->address(), ':'), 1),
    ]);
    app()->forgetInstance(DaemonTransport::class);
    app()->instance(Transport::class, app(DaemonTransport::class));

    // The default handler would also log the reported exception as an error; at debug level the
    // agent (log_level warning) does not record that log line, so the route's warning is the only one.
    app(ExceptionHandler::class)->level(CheckoutFailedException::class, LogLevel::DEBUG);

    $this->request_trace_ids = [];

    Route::get('/checkout', function () {
        $this->request_trace_ids[] = app(TraceContext::class)->traceId();

        DB::select('select 1');
        DB::select('select 2');
        DB::select('select 3');
        Cache::get('cart:missing');
        Cache::put('cart:latest', 'value', 60);
        Log::warning('Checkout is slow');
        Http::get('https://payments.example.com/charges');

        throw new CheckoutFailedException('Card declined');
    });

    Http::fake(['payments.example.com/*' => Http::response(['id' => 1], 200)]);
});

afterEach(function (): void {
    $this->daemon->close();
    app(TraceContext::class)->end();
    restoreTelemetryConnection();
    dropTelemetrySchema();
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});

/**
 * Tick the daemon until it has read every connection and delivered every buffered event (at most
 * the given number of ticks), and return the number of ticks taken.
 */
function drainDaemon(Daemon $daemon, Batcher $batcher, int $max_ticks = 20): int
{
    for ($tick = 1; $tick <= $max_ticks; $tick++) {
        $daemon->tick();

        if ($tick >= 2 && $daemon->connectionCount() === 0 && $batcher->count() === 0 && $daemon->outbox()->pending() === []) {
            return $tick;
        }
    }

    throw new RuntimeException("The daemon still had events after {$max_ticks} ticks.");
}

/**
 * Send one traced request to /checkout, let the agent flush its trace to the daemon, drain the
 * daemon and return the request's trace id.
 */
function checkout(): string
{
    test()->get('/checkout')->assertServerError();

    // The trace stays current after the request; end it, so the daemon's own writes are never recorded.
    app(TraceContext::class)->end();
    drainDaemon(test()->daemon, test()->batcher);

    return (string) last(test()->request_trace_ids);
}

/**
 * The row count of every raw table and of exception_groups for the given environment.
 *
 * @return array<string, int>
 */
function environmentRowCounts(int $environment_id): array
{
    $counts = [];

    foreach ([...Schema::PARTITIONED_TABLES, 'exception_groups'] as $table) {
        $counts[$table] = TelemetryRows::countFor($table, $environment_id);
    }

    return $counts;
}

/**
 * The distinct trace ids stored in the given raw table for the given environment.
 *
 * @return array<int, string>
 */
function storedTraceIds(string $table, int $environment_id): array
{
    return DB::connection('app_watch')->table($table)->where('environment_id', $environment_id)->distinct()->pluck('trace_id')->all();
}

/**
 * Assert every row of the raw table lives in the daily partition of its own `recorded_at` UTC day,
 * which is the given day.
 */
function expectRowsInPartitionOf(string $table, string $ymd): void
{
    $rows = DB::connection('app_watch')->table($table)->get(['id', 'recorded_at']);

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        expect(CarbonImmutable::parse((string) $row->recorded_at)->utc()->format('Ymd'))->toBe($ymd)
            ->and(TelemetryRows::partitionOf($table, (int) $row->id))->toBe("{$table}_p{$ymd}");
    }
}

it('stores every event of a traced request, a sync job and a command in the raw tables under the environment', function (): void {
    $today = CarbonImmutable::now('UTC')->format('Ymd');

    $trace_id = checkout();

    Artisan::call('orders:sync');
    app(TraceContext::class)->end();
    drainDaemon($this->daemon, $this->batcher);

    expect(environmentRowCounts(42))->toBe([...array_fill_keys(Schema::PARTITIONED_TABLES, 0), ...CHECKOUT_ROWS, 'job_runs' => 1, 'exception_groups' => 1])
        ->and(TelemetryRows::countFor('requests', 1))->toBe(0);

    foreach (array_keys(CHECKOUT_ROWS) as $table) {
        expect(storedTraceIds($table, 42))->toBe([$trace_id]);
        expectRowsInPartitionOf($table, $today);
    }

    $job_run = DB::connection('app_watch')->table('job_runs')->first();
    $group = DB::connection('app_watch')->table('exception_groups')->first();

    expect($job_run->trace_id)->toBe(TracedJob::$trace_ids[0])
        ->and($job_run->trace_id)->not->toBe($trace_id)
        ->and($job_run->job_class)->toBe(TracedJob::class)
        ->and($job_run->status)->toBe(JobStatusEnum::Processed->value)
        ->and(TelemetryRows::partitionOf('job_runs', (int) $job_run->id))->toBe("job_runs_p{$today}")
        ->and($group->class)->toBe(CheckoutFailedException::class)
        ->and($group->environment_id)->toBe(42)
        ->and($group->occurrences)->toBe(1)
        ->and(DB::connection('app_watch')->table('exception_occurrences')->value('exception_group_id'))->toBe($group->id)
        ->and(app(Recorder::class)->flush($trace_id))->toBe([])
        ->and($this->daemon->outbox()->spool()->isEmpty())->toBeTrue();
});

it('stores a traced Artisan command run in command_runs with its redacted arguments', function (): void {
    // Laravel only re-routes Symfony's command events to CommandStarting / CommandFinished outside
    // unit tests, so turn it on and rebuild Artisan with the dispatcher.
    $kernel = app(Kernel::class);
    $kernel->rerouteSymfonyCommandEvents();
    $kernel->setArtisan(null);
    $kernel->registerCommand(new ReportCommand);
    app(TraceContext::class)->end();

    Artisan::call('fixture:report', ['email' => 'jane@example.com', '--exit' => 3]);
    $trace_id = app(TraceContext::class)->traceId();
    app(TraceContext::class)->end();
    drainDaemon($this->daemon, $this->batcher);

    $run = DB::connection('app_watch')->table('command_runs')->sole();

    expect($run->environment_id)->toBe(42)
        ->and($run->trace_id)->toBe($trace_id)
        ->and($run->command)->toBe('fixture:report')
        ->and(json_decode((string) $run->arguments, true))->toEqual(['email' => '?', 'exit' => '?'])
        ->and($run->exit_code)->toBe(3)
        ->and($run->failed)->toBeTrue()
        ->and($run->duration_ms)->toBeGreaterThan(0.0)
        ->and((string) $run->arguments)->not->toContain('jane@example.com')
        ->and(TelemetryRows::partitionOf('command_runs', (int) $run->id))->toBe('command_runs_p'.CarbonImmutable::now('UTC')->format('Ymd'));
});

it('counts a second identical exception in the same exception group', function (): void {
    checkout();
    $first = DB::connection('app_watch')->table('exception_groups')->first();

    checkout();
    $groups = DB::connection('app_watch')->table('exception_groups')->get();

    expect($groups)->toHaveCount(1)
        ->and($groups[0]->id)->toBe($first->id)
        ->and($groups[0]->occurrences)->toBe(2)
        ->and(CarbonImmutable::parse((string) $groups[0]->last_seen_at)->greaterThanOrEqualTo(CarbonImmutable::parse((string) $first->last_seen_at)))->toBeTrue()
        ->and($groups[0]->first_seen_at)->toBe($first->first_seen_at)
        ->and(TelemetryRows::countFor('exception_occurrences', 42))->toBe(2);
});

it('routes events recorded at 23:59:59 UTC into that day\'s partitions', function (): void {
    CarbonImmutable::setTestNow('2026-11-03 23:59:59.000000 UTC');
    Carbon::setTestNow('2026-11-03 23:59:59.000000 UTC');
    // Partitions from the frozen day on, created by the same install command, with the fake transport.
    app()->instance(Transport::class, new FakeTransport);
    installTelemetrySchema();
    app()->instance(Transport::class, app(DaemonTransport::class));

    $trace_id = (string) Str::uuid();
    // The app captures recorded_at from the system clock, so the frozen time is passed explicitly.
    $metadata = EventMetadata::capture($trace_id, CarbonImmutable::now());

    app(DaemonTransport::class)->send([
        new RequestEventPayload($metadata, 'GET', 'checkout', '/checkout', 500, 12.5, null),
        new QueryEventPayload($metadata, 'pgsql', 'select ?', sha1('select ?'), 0.5),
        new JobEventPayload($metadata, TracedJob::class, 'default', 'sync', JobStatusEnum::Processed, 1, 3.0),
        new OutgoingRequestEventPayload($metadata, 'GET', 'payments.example.com', 'https://payments.example.com/charges', 200, false, 20.0),
        new CacheEventPayload($metadata, 'array', 'cart:missing', CacheOperationEnum::Miss, null),
        new LogEventPayload($metadata, LogLevelEnum::Warning, 'Checkout is slow', null),
        new ExceptionEventPayload($metadata, CheckoutFailedException::class, 'Card declined', '/app/Checkout.php', 10, [], false),
        new CommandEventPayload($metadata, 'orders:sync', [], 0, 15.0),
    ]);
    drainDaemon($this->daemon, $this->batcher);

    foreach (Schema::PARTITIONED_TABLES as $table) {
        expect(TelemetryRows::countFor($table, 42))->toBe(1);
        expectRowsInPartitionOf($table, '20261103');
    }
});

it('keeps two environments sharing one database apart', function (): void {
    $first_trace_id = checkout();
    $environment_42_counts = environmentRowCounts(42);

    config(['app-watch.environment_id' => 43]);
    $second_trace_id = checkout();

    expect(environmentRowCounts(42))->toBe($environment_42_counts)
        ->and(environmentRowCounts(43))->toBe([...array_fill_keys(Schema::PARTITIONED_TABLES, 0), ...CHECKOUT_ROWS, 'exception_groups' => 1])
        ->and($second_trace_id)->not->toBe($first_trace_id);

    foreach (array_keys(CHECKOUT_ROWS) as $table) {
        expect(storedTraceIds($table, 43))->toBe([$second_trace_id])
            ->and(storedTraceIds($table, 42))->toBe([$first_trace_id]);
    }
});

it('spools the events while the database is unreachable and stores them once it is back', function (): void {
    breakTelemetryConnection();

    test()->get('/checkout')->assertServerError();
    app(TraceContext::class)->end();
    $trace_id = (string) last($this->request_trace_ids);
    $spool = $this->daemon->outbox()->spool();

    for ($tick = 0; $tick < 10 && $spool->isEmpty(); $tick++) {
        // Skip each retry's backoff (the outbox's clock), so every tick makes a delivery attempt.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(2));
        $this->daemon->tick();
    }

    expect($spool->isEmpty())->toBeFalse()
        ->and(spooledBatches()[0])->toHaveCount(array_sum(CHECKOUT_ROWS))
        ->and($this->daemon->outbox()->pending())->toBe([]);

    restoreTelemetryConnection();

    expect(array_sum(environmentRowCounts(42)))->toBe(0);

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(2));
    $result = $this->daemon->tick();

    expect($result?->stored_count)->toBe(array_sum(CHECKOUT_ROWS))
        ->and($spool->isEmpty())->toBeTrue()
        ->and($spool->files())->toBe([])
        ->and(environmentRowCounts(42))->toMatchArray([...CHECKOUT_ROWS, 'exception_groups' => 1])
        ->and(storedTraceIds('requests', 42))->toBe([$trace_id]);
});

it('stores only the app\'s own queries after the agent\'s rollup and partition commands run', function (): void {
    Artisan::call('app-watch:rollups', ['kind' => 'minute']);
    Artisan::call('app-watch:rollups', ['kind' => 'hour']);
    Artisan::call('app-watch:rollups', ['kind' => 'prune']);
    Artisan::call('app-watch:partitions');
    app(TraceContext::class)->end();
    drainDaemon($this->daemon, $this->batcher);

    expect(DB::connection('app_watch')->table('queries')->count())->toBe(0);

    checkout();

    expect(DB::connection('app_watch')->table('queries')->orderBy('id')->get(['environment_id', 'connection', 'sql'])->map(fn (object $row): array => (array) $row)->all())
        ->toBe(array_fill(0, 3, ['environment_id' => 42, 'connection' => 'pgsql', 'sql' => 'select ?']));
});
