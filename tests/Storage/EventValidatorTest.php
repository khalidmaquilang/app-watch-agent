<?php

declare(strict_types=1);

use AppWatch\Agent\Storage\EventValidator;
use Illuminate\Support\Arr;

beforeEach(function (): void {
    $this->validator = app(EventValidator::class);
});

it('accepts every fixture event and returns exactly its contract fields', function (string $type): void {
    $event = fixtureEvent($type);

    $validated = $this->validator->validate($event);

    expect($validated)->not->toBeNull()
        ->and(array_keys($validated))->toBe(array_keys($event))
        ->and($validated)->toEqual($event);
})->with(['request', 'query', 'job', 'outgoing_request', 'cache', 'log', 'exception']);

it('drops unknown keys such as an environment_id from the event', function (): void {
    expect($this->validator->validate(storageEvent('request', ['environment_id' => 99, 'extra' => true])))
        ->not->toHaveKey('environment_id')
        ->not->toHaveKey('extra');
});

it('accepts optional fields as null or missing and returns them as null', function (string $type, array $nulls, array $missing): void {
    $event = Arr::except(storageEvent($type, $nulls), $missing);

    $validated = $this->validator->validate($event);

    expect($validated)->not->toBeNull();

    foreach ([...array_keys($nulls), ...$missing] as $field) {
        expect($validated)->toHaveKey($field)
            ->and($validated[$field])->toBeNull();
    }
})->with([
    'request' => ['request', ['route' => null, 'memory_peak_bytes' => null], ['deploy']],
    'outgoing request' => ['outgoing_request', ['status_code' => null], []],
    'cache' => ['cache', ['duration_ms' => null], []],
    'log' => ['log', [], ['context']],
]);

it('casts integer durations to float', function (): void {
    expect($this->validator->validate(storageEvent('request', ['duration_ms' => 0]))['duration_ms'])->toBe(0.0);
});

it('accepts limits at their boundaries', function (): void {
    expect($this->validator->validate(storageEvent('request', ['path' => '/'.str_repeat('a', 2047), 'status_code' => 599])))->not->toBeNull()
        ->and($this->validator->validate(storageEvent('request', ['status_code' => 100])))->not->toBeNull()
        ->and($this->validator->validate(storageEvent('request', ['host' => str_repeat('é', 255)])))->not->toBeNull();
});

it('accepts recorded_at far in the past or future and in another offset', function (string $recorded_at): void {
    expect($this->validator->validate(storageEvent('request', ['recorded_at' => $recorded_at]))['recorded_at'] ?? null)->toBe($recorded_at);
})->with([
    'past' => '1999-01-01T00:00:00.000001+00:00',
    'future' => '2099-12-31T23:59:59.999999+08:00',
]);

it('accepts a log context of exactly 64 KB serialized', function (): void {
    $overhead = strlen((string) json_encode(['blob' => '']));

    expect($this->validator->validate(storageEvent('log', ['context' => ['blob' => str_repeat('x', EventValidator::MAX_CONTEXT_BYTES - $overhead)]])))->not->toBeNull();
});

it('rejects an event that breaks the contract', function (mixed $event): void {
    expect($this->validator->validate($event))->toBeNull();
})->with([
    'not an array' => fn (): string => 'foo',
    'unknown type' => fn (): array => storageEvent('job', ['type' => 'span']),
    'missing type' => fn (): array => Arr::except(fixtureEvent('query'), 'type'),
    'a type that is not a scalar' => fn (): array => storageEvent('request', ['type' => ['request']]),
    'an integer type' => fn (): array => storageEvent('request', ['type' => 1]),
    'missing trace_id' => fn (): array => Arr::except(fixtureEvent('outgoing_request'), 'trace_id'),
    'non-uuid trace_id' => fn (): array => storageEvent('request', ['trace_id' => 'abc']),
    'missing recorded_at' => fn (): array => Arr::except(fixtureEvent('request'), 'recorded_at'),
    'invalid recorded_at' => fn (): array => storageEvent('cache', ['recorded_at' => 'yesterday']),
    'recorded_at without microseconds' => fn (): array => storageEvent('cache', ['recorded_at' => '2026-11-03T05:41:03+00:00']),
    'recorded_at that is not a string' => fn (): array => storageEvent('cache', ['recorded_at' => 1730612463]),
    'missing host' => fn (): array => Arr::except(fixtureEvent('request'), 'host'),
    'negative duration_ms' => fn (): array => storageEvent('query', ['duration_ms' => -0.1]),
    'duration_ms as a string' => fn (): array => storageEvent('query', ['duration_ms' => '1.27']),
    'status_code 700' => fn (): array => storageEvent('request', ['status_code' => 700]),
    'status_code 99' => fn (): array => storageEvent('request', ['status_code' => 99]),
    'status_code as a string' => fn (): array => storageEvent('request', ['status_code' => '200']),
    'outgoing status_code 700' => fn (): array => storageEvent('outgoing_request', ['status_code' => 700]),
    'path with a query string' => fn (): array => storageEvent('request', ['path' => '/orders?page=2']),
    'path over 2048 characters' => fn (): array => storageEvent('request', ['path' => '/'.str_repeat('a', 2048)]),
    'url with a query string' => fn (): array => storageEvent('outgoing_request', ['url' => 'https://api.payments.example/v2/charges?key=secret']),
    'host over 255 characters' => fn (): array => storageEvent('request', ['host' => str_repeat('h', 256)]),
    'deploy over 100 characters' => fn (): array => storageEvent('request', ['deploy' => str_repeat('d', 101)]),
    'sql over 10 000 characters' => fn (): array => storageEvent('query', ['sql' => str_repeat('s', 10001)]),
    'sql_hash that is not sha1' => fn (): array => storageEvent('query', ['sql_hash' => 'not-a-hash']),
    'unknown job status' => fn (): array => storageEvent('job', ['status' => 'running']),
    'job attempt 0' => fn (): array => storageEvent('job', ['attempt' => 0]),
    'missing outgoing failed flag' => fn (): array => Arr::except(fixtureEvent('outgoing_request'), 'failed'),
    'failed flag as an integer' => fn (): array => storageEvent('outgoing_request', ['failed' => 0]),
    'unknown cache operation' => fn (): array => storageEvent('cache', ['operation' => 'flush']),
    'cache key over 255 characters' => fn (): array => storageEvent('cache', ['key' => str_repeat('k', 256)]),
    'unknown log level' => fn (): array => storageEvent('log', ['level' => 'fatal']),
    'log message over 10 000 characters' => fn (): array => storageEvent('log', ['message' => str_repeat('m', 10001)]),
    'log context that is not an array' => fn (): array => storageEvent('log', ['context' => 'context']),
    'log context over 64 KB serialized' => fn (): array => storageEvent('log', ['context' => ['blob' => str_repeat('x', EventValidator::MAX_CONTEXT_BYTES)]]),
    'exception trace over 100 frames' => fn (): array => storageEvent('exception', ['trace' => array_fill(0, EventValidator::MAX_TRACE_FRAMES + 1, fixtureEvent('exception')['trace'][0])]),
    'exception trace that is not a list' => fn (): array => storageEvent('exception', ['trace' => ['first' => fixtureEvent('exception')['trace'][0]]]),
    'exception trace frame that is not an array' => fn (): array => storageEvent('exception', ['trace' => ['frame']]),
    'exception frame without function' => fn (): array => storageEvent('exception', ['trace' => [Arr::except(fixtureEvent('exception')['trace'][0], 'function')]]),
    'exception line as a string' => fn (): array => storageEvent('exception', ['line' => '88']),
    'missing exception handled flag' => fn (): array => Arr::except(fixtureEvent('exception'), 'handled'),
    'fields of another type' => fn (): array => storageEvent('request', ['type' => 'query']),
]);

it('parses only contract timestamps', function (): void {
    expect(EventValidator::parseRecordedAt('2026-11-04T07:30:00.000000+08:00')?->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-03 23:30:00')
        ->and(EventValidator::parseRecordedAt('2026-11-03'))->toBeNull()
        ->and(EventValidator::parseRecordedAt(null))->toBeNull();
});
