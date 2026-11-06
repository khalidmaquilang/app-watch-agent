<?php

declare(strict_types=1);

namespace AppWatch\Agent\Storage;

use AppWatch\Agent\Enums\CacheOperationEnum;
use AppWatch\Agent\Enums\JobStatusEnum;
use AppWatch\Agent\Enums\LogLevelEnum;
use AppWatch\Agent\Enums\TelemetryTypeEnum;
use Carbon\CarbonImmutable;
use DateTimeImmutable;

/**
 * Validates one raw event against the v1 event contract (the rules App Watch's ingest Data objects
 * enforced) and normalises it to exactly the contract fields.
 */
final class EventValidator
{
    /**
     * ISO-8601 with microseconds, e.g. 2026-11-03T05:41:03.123456+00:00.
     */
    public const string DATE_FORMAT = 'Y-m-d\TH:i:s.uP';

    /**
     * The most stack frames accepted per exception.
     */
    public const int MAX_TRACE_FRAMES = 100;

    /**
     * The largest JSON-encoded log `context` accepted, in bytes (64 KB).
     */
    public const int MAX_CONTEXT_BYTES = 65536;

    /**
     * The fields every event carries: [kind, nullable, max length or range]. Kinds: string, int,
     * number (int or float, stored as float), bool.
     *
     * @var array<string, array{0: string, 1: bool, 2?: int|array{0: int, 1: int}}>
     */
    public const array COMMON_FIELDS = [
        'host' => ['string', false, 255],
        'deploy' => ['string', true, 100],
    ];

    /**
     * The type-specific fields per event type, in contract order. Enum, uuid, sha1, path, context
     * and trace fields are checked separately.
     *
     * @var array<string, array<string, array{0: string, 1: bool, 2?: int|array{0: int, 1: int}}>>
     */
    public const array FIELDS = [
        'request' => [
            'method' => ['string', false, 16],
            'route' => ['string', true, 1024],
            'path' => ['url', false, 2048],
            'status_code' => ['int', false, [100, 599]],
            'duration_ms' => ['number', false],
            'memory_peak_bytes' => ['int', true, [0, PHP_INT_MAX]],
        ],
        'query' => [
            'connection' => ['string', false, 255],
            'sql' => ['string', false, 10000],
            'sql_hash' => ['sha1', false],
            'duration_ms' => ['number', false],
        ],
        'job' => [
            'job_class' => ['string', false, 255],
            'queue' => ['string', false, 255],
            'connection' => ['string', false, 255],
            'status' => ['job_status', false],
            'attempt' => ['int', false, [1, PHP_INT_MAX]],
            'duration_ms' => ['number', false],
        ],
        'outgoing_request' => [
            'method' => ['string', false, 16],
            'target_host' => ['string', false, 255],
            'url' => ['url', false, 2048],
            'status_code' => ['int', true, [100, 599]],
            'failed' => ['bool', false],
            'duration_ms' => ['number', false],
        ],
        'cache' => [
            'store' => ['string', false, 255],
            'key' => ['string', false, 255],
            'operation' => ['cache_operation', false],
            'duration_ms' => ['number', true],
        ],
        'log' => [
            'level' => ['log_level', false],
            'message' => ['string', false, 10000],
            'context' => ['context', true],
        ],
        'exception' => [
            'class' => ['string', false, 255],
            'message' => ['string', false, 10000],
            'file' => ['string', false, 2048],
            'line' => ['int', false, [0, PHP_INT_MAX]],
            'trace' => ['trace', false],
            'handled' => ['bool', false],
        ],
    ];

    /**
     * The fields of one exception trace frame.
     *
     * @var array<string, array{0: string, 1: bool, 2?: int|array{0: int, 1: int}}>
     */
    public const array FRAME_FIELDS = [
        'file' => ['string', true, 2048],
        'line' => ['int', true, [0, PHP_INT_MAX]],
        'function' => ['string', false, 1024],
        'class' => ['string', true, 255],
    ];

    /**
     * The event with exactly its contract fields in contract order (unknown keys such as an
     * `environment_id` are dropped, missing nullable fields become null, `duration_ms` is a float),
     * or null when it breaks the contract: not an array, unknown type, missing or invalid
     * `trace_id`/`recorded_at`, a missing required field, a wrong type, a value out of range or
     * longer than its column.
     *
     * @return array<string, mixed>|null
     */
    public function validate(mixed $event): ?array
    {
        if (! is_array($event)) {
            return null;
        }

        $type = $event['type'] ?? null;

        // A type without contract fields (e.g. `command`, until its table and writer exist) is dropped as malformed.
        if (! is_string($type) || TelemetryTypeEnum::tryFrom($type) === null || ! array_key_exists($type, self::FIELDS)) {
            return null;
        }

        $trace_id = $event['trace_id'] ?? null;

        if (! is_string($trace_id) || preg_match('/^[\da-fA-F]{8}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{12}$/D', $trace_id) !== 1) {
            return null;
        }

        if (self::parseRecordedAt($event['recorded_at'] ?? null) === null) {
            return null;
        }

        $normalized = ['type' => $type, 'trace_id' => $trace_id, 'recorded_at' => $event['recorded_at']];
        $fields = $this->fields($event, [...self::COMMON_FIELDS, ...self::FIELDS[$type]]);

        return $fields === null ? null : [...$normalized, ...$fields];
    }

    /**
     * Parse a contract `recorded_at` (DATE_FORMAT, microseconds and offset required); null when it is
     * not one.
     */
    public static function parseRecordedAt(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!'.self::DATE_FORMAT, $value);

        if ($parsed === false || $parsed->format(self::DATE_FORMAT) !== $value) {
            return null;
        }

        return CarbonImmutable::instance($parsed);
    }

    /**
     * The validated values of the given fields, in the given order; null when any is invalid.
     *
     * @param  array<array-key, mixed>  $source
     * @param  array<string, array{0: string, 1: bool, 2?: int|array{0: int, 1: int}}>  $rules
     * @return array<string, mixed>|null
     */
    private function fields(array $source, array $rules): ?array
    {
        $values = [];

        foreach ($rules as $field => $rule) {
            $value = $source[$field] ?? null;

            if ($value === null) {
                if (! $rule[1]) {
                    return null;
                }

                $values[$field] = null;

                continue;
            }

            $valid = $this->value($rule[0], $value, $rule[2] ?? null);

            if ($valid === null) {
                return null;
            }

            $values[$field] = $valid;
        }

        return $values;
    }

    /**
     * The validated (and for numbers, float-cast) non-null value of one field; null when it is invalid.
     *
     * @param  int|array{0: int, 1: int}|null  $limit  max length for strings, [min, max] for ints
     */
    private function value(string $kind, mixed $value, int|array|null $limit): mixed
    {
        return match ($kind) {
            'string' => is_string($value) && (! is_int($limit) || mb_strlen($value) <= $limit) ? $value : null,
            'url' => is_string($value) && (! is_int($limit) || mb_strlen($value) <= $limit) && preg_match('/[?#]/', $value) !== 1 ? $value : null,
            'int' => is_int($value) && (! is_array($limit) || ($value >= $limit[0] && $value <= $limit[1])) ? $value : null,
            'number' => (is_int($value) || is_float($value)) && $value >= 0 ? (float) $value : null,
            'bool' => is_bool($value) ? $value : null,
            'sha1' => is_string($value) && preg_match('/^[0-9a-f]{40}$/D', $value) === 1 ? $value : null,
            'job_status' => is_string($value) && JobStatusEnum::tryFrom($value) !== null ? $value : null,
            'cache_operation' => is_string($value) && CacheOperationEnum::tryFrom($value) !== null ? $value : null,
            'log_level' => is_string($value) && LogLevelEnum::tryFrom($value) !== null ? $value : null,
            'context' => $this->context($value),
            'trace' => $this->trace($value),
            default => null,
        };
    }

    /**
     * The log context when it is an array of at most MAX_CONTEXT_BYTES serialized; null otherwise.
     *
     * @return array<array-key, mixed>|null
     */
    private function context(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $encoded = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);

        return $encoded !== false && strlen($encoded) <= self::MAX_CONTEXT_BYTES ? $value : null;
    }

    /**
     * The exception trace as a list of normalised frames (file, line, function, class) when it is a
     * list of at most MAX_TRACE_FRAMES valid frame arrays; null otherwise.
     *
     * @return list<array<string, mixed>>|null
     */
    private function trace(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > self::MAX_TRACE_FRAMES) {
            return null;
        }

        $frames = [];

        foreach ($value as $frame) {
            $normalized = is_array($frame) ? $this->fields($frame, self::FRAME_FIELDS) : null;

            if ($normalized === null) {
                return null;
            }

            $frames[] = $normalized;
        }

        return $frames;
    }
}
