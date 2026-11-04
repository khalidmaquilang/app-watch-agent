<?php

declare(strict_types=1);

use AppWatch\Agent\Listeners\RecordQueryListener;
use AppWatch\Agent\Payloads\EventPayload;
use AppWatch\Agent\Payloads\QueryEventPayload;
use AppWatch\Agent\Recorder;
use AppWatch\Agent\Support\TraceContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    config([
        'app-watch.enabled' => true,
    ]);

    $this->trace_context = app(TraceContext::class);
    $this->trace_context->end();

    Schema::dropIfExists('agent_users');
    Schema::create('agent_users', function (Blueprint $table): void {
        $table->id();
        $table->string('email');
    });

    DB::table('agent_users')->insert(['id' => 5, 'email' => 'secret@example.com']);

    $this->recorder = app(Recorder::class);
    $this->recorder->reset();
});

afterEach(function (): void {
    Schema::dropIfExists('agent_users');
});

/**
 * Flush the trace and return its query events as arrays.
 *
 * @return array<int, array<string, mixed>>
 */
function flushedQueryEvents(?string $trace_id): array
{
    $events = array_filter(
        app(Recorder::class)->flush((string) $trace_id),
        fn (EventPayload $event): bool => $event instanceof QueryEventPayload,
    );

    return array_values(array_map(fn (EventPayload $event): array => $event->toArray(), $events));
}

/**
 * A model on the test table.
 */
final class AgentUser extends Model
{
    public $timestamps = false;

    protected $table = 'agent_users';

    protected $guarded = [];
}

it('records an Eloquent query with normalized SQL and no bindings', function (): void {
    $trace_id = $this->trace_context->start();

    $user = AgentUser::query()->where('id', 5)->where('email', 'secret@example.com')->first();

    $events = flushedQueryEvents($trace_id);

    expect($user)->not->toBeNull()
        ->and($events)->toHaveCount(1)
        ->and($events[0])->toMatchArray([
            'type' => 'query',
            'trace_id' => $trace_id,
            'connection' => 'pgsql',
            'sql' => 'select * from "agent_users" where "id" = ? and "email" = ? limit ?',
            'sql_hash' => sha1('select * from "agent_users" where "id" = ? and "email" = ? limit ?'),
        ])
        ->and($events[0])->not->toHaveKey('bindings')
        ->and($events[0]['duration_ms'])->toBeFloat()->toBeGreaterThanOrEqual(0.0)
        ->and(json_encode($events[0], JSON_THROW_ON_ERROR))->not->toContain('secret');
});

it('removes literals written into raw SQL', function (): void {
    $trace_id = $this->trace_context->start();

    DB::select("select * from agent_users where id = 5 and email = 'secret@example.com'");
    DB::select("select * from agent_users where id = 9 and email = 'other@example.com'");

    $events = flushedQueryEvents($trace_id);

    expect($events)->toHaveCount(2)
        ->and($events[0]['sql'])->toBe('select * from agent_users where id = ? and email = ?')
        ->and($events[0]['sql_hash'])->toBe($events[1]['sql_hash'])
        ->and(json_encode($events, JSON_THROW_ON_ERROR))->not->toContain('secret')->not->toContain('= 5');
});

it('aggregates whereIn queries of any length under one hash', function (): void {
    $trace_id = $this->trace_context->start();

    AgentUser::query()->whereIn('id', [1, 2, 3])->get();
    AgentUser::query()->whereIn('id', [4])->get();

    $events = flushedQueryEvents($trace_id);

    expect($events[0]['sql'])->toBe('select * from "agent_users" where "id" in (?)')
        ->and($events[1]['sql_hash'])->toBe($events[0]['sql_hash']);
});

it('records the query duration from the event', function (): void {
    $trace_id = (string) $this->trace_context->start();

    event(new QueryExecuted('select 1', [], 12.34567, DB::connection()));

    expect(flushedQueryEvents($trace_id)[0])->toMatchArray(['sql' => 'select ?', 'duration_ms' => 12.346]);
});

it('honours the ignore.queries regexes against the normalized SQL', function (): void {
    config(['app-watch.ignore.queries' => ['/^select \* from "agent_users" where "id" = \?/']]);
    $trace_id = $this->trace_context->start();

    AgentUser::query()->where('id', 5)->first();
    DB::table('agent_users')->count();

    $events = flushedQueryEvents($trace_id);

    expect($events)->toHaveCount(1)
        ->and($events[0]['sql'])->toBe('select count(*) as "aggregate" from "agent_users"');
});

it('records nothing when queries are not sampled', function (): void {
    config(['app-watch.sample_rates.query' => 0]);
    $trace_id = $this->trace_context->start();

    AgentUser::query()->first();

    expect(flushedQueryEvents($trace_id))->toBe([]);
});

it('records nothing inside an unsampled trace', function (): void {
    $trace_id = $this->trace_context->start(ignored: true);

    AgentUser::query()->first();

    expect(flushedQueryEvents($trace_id))->toBe([]);
});

it('records nothing outside a trace', function (): void {
    AgentUser::query()->first();

    expect($this->trace_context->traceId())->toBeNull();
});

it('records nothing without an environment id', function (): void {
    $trace_id = $this->trace_context->start();
    config(['app-watch.environment_id' => null]);

    AgentUser::query()->first();

    expect($this->recorder->flush((string) $trace_id))->toBe([]);
});

it('is registered as a singleton', function (): void {
    expect(app(RecordQueryListener::class))->toBe(app(RecordQueryListener::class));
});
