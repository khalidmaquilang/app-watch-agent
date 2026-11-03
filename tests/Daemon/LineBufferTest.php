<?php

declare(strict_types=1);

use AppWatch\Agent\Daemon\LineBuffer;

it('decodes every complete line into an event', function (): void {
    $events = daemonEvents(3);
    $buffer = new LineBuffer;

    $decoded = $buffer->push(implode("\n", array_map(json_encode(...), $events))."\n");

    expect($decoded)->toBe($events)
        ->and($buffer->malformedCount())->toBe(0);
});

it('reassembles a line split across reads', function (): void {
    $line = json_encode(fixtureEvent('query'))."\n";
    $buffer = new LineBuffer;

    expect($buffer->push(substr($line, 0, 10)))->toBe([])
        ->and($buffer->push(substr($line, 10, 30)))->toBe([])
        ->and($buffer->push(substr($line, 40)))->toBe([fixtureEvent('query')]);
});

it('skips and counts malformed lines without stopping the following ones', function (string $malformed): void {
    $buffer = new LineBuffer;
    $event = fixtureEvent('log');

    $decoded = $buffer->push($malformed."\n".json_encode($event)."\n");

    expect($decoded)->toBe([$event])
        ->and($buffer->malformedCount())->toBe(1);
})->with([
    'invalid JSON' => '{"type": "request",',
    'a scalar' => '42',
    'a list' => '[1, 2]',
    'no type' => '{"trace_id": "t-1"}',
    'a non-string type' => '{"type": 5}',
]);

it('ignores blank lines without counting them', function (): void {
    $buffer = new LineBuffer;

    expect($buffer->push("\n  \n".json_encode(fixtureEvent('job'))."\n\n"))->toBe([fixtureEvent('job')])
        ->and($buffer->malformedCount())->toBe(0);
});

it('decodes a last line without a trailing newline when the connection closes', function (): void {
    $buffer = new LineBuffer;

    expect($buffer->push(json_encode(fixtureEvent('cache'))))->toBe([])
        ->and($buffer->finish())->toBe([fixtureEvent('cache')])
        ->and($buffer->finish())->toBe([]);
});

it('counts an unfinished malformed last line when the connection closes', function (): void {
    $buffer = new LineBuffer;
    $buffer->push('{"type":');

    expect($buffer->finish())->toBe([])
        ->and($buffer->malformedCount())->toBe(1);
});

it('discards an over-long line once and keeps decoding the lines after it', function (): void {
    $buffer = new LineBuffer;

    expect($buffer->push(str_repeat('x', LineBuffer::MAX_LINE_BYTES + 1)))->toBe([])
        ->and($buffer->push(str_repeat('x', 1000)))->toBe([])
        ->and($buffer->push("tail\n".json_encode(fixtureEvent('request'))."\n"))->toBe([fixtureEvent('request')])
        ->and($buffer->malformedCount())->toBe(1);
});
