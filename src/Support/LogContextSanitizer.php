<?php

declare(strict_types=1);

namespace AppWatch\Agent\Support;

use BackedEnum;
use DateTimeInterface;
use JsonSerializable;
use Stringable;
use Throwable;
use UnitEnum;

/**
 * Turns a log context into plain JSON-safe data that is safe to leave the monitored app: values
 * under sensitive keys become `[redacted]` (at any depth), exceptions are reduced to their class and
 * message, other objects to a string or their class name, and strings are made valid UTF-8. A
 * context larger than 64 KB once serialized is replaced with a `_truncated` marker.
 */
final readonly class LogContextSanitizer
{
    /**
     * The replacement for a sensitive value.
     */
    public const string REDACTED = '[redacted]';

    /**
     * The largest JSON-encoded context kept, in bytes (the v1 contract limit).
     */
    public const int MAX_CONTEXT_BYTES = 65536;

    /**
     * How deep nested arrays are followed; deeper values become `[max depth]`.
     */
    public const int MAX_DEPTH = 10;

    /**
     * Key fragments that mark a value as sensitive. A key matches when, lower-cased and with `-`
     * turned into `_`, it contains one of them (so `X-Api-Key` and `access_token` match too).
     *
     * @var array<int, string>
     */
    public const array SENSITIVE_KEYS = ['password', 'token', 'secret', 'authorization', 'cookie', 'api_key'];

    /**
     * The sanitized context, `['_truncated' => true]` when it is too large, or null when empty.
     *
     * @param  array<array-key, mixed>  $context
     * @return array<array-key, mixed>|null
     */
    public function sanitize(array $context): ?array
    {
        if ($context === []) {
            return null;
        }

        $sanitized = $this->sanitizeArray($context, 1);
        $encoded = json_encode($sanitized);

        if ($encoded === false || strlen($encoded) > self::MAX_CONTEXT_BYTES) {
            return ['_truncated' => true];
        }

        return $sanitized;
    }

    /**
     * Whether the key holds a sensitive value.
     */
    public function isSensitiveKey(int|string $key): bool
    {
        if (is_int($key)) {
            return false;
        }

        $normalized_key = str_replace('-', '_', strtolower($key));

        foreach (self::SENSITIVE_KEYS as $sensitive_key) {
            if (str_contains($normalized_key, $sensitive_key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sanitize every entry of the array.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function sanitizeArray(array $values, int $depth): array
    {
        $sanitized = [];

        foreach ($values as $key => $value) {
            $safe_key = is_string($key) ? mb_scrub($key, 'UTF-8') : $key;

            $sanitized[$safe_key] = $this->isSensitiveKey($key) ? self::REDACTED : $this->sanitizeValue($value, $depth);
        }

        return $sanitized;
    }

    /**
     * One JSON-safe value.
     */
    private function sanitizeValue(mixed $value, int $depth): mixed
    {
        return match (true) {
            $value === null, is_bool($value), is_int($value) => $value,
            is_float($value) => match (true) {
                is_nan($value) => 'NAN',
                is_infinite($value) => $value > 0 ? 'INF' : '-INF',
                default => $value,
            },
            is_string($value) => mb_scrub($value, 'UTF-8'),
            is_array($value) => $depth >= self::MAX_DEPTH ? '[max depth]' : $this->sanitizeArray($value, $depth + 1),
            $value instanceof Throwable => [
                'class' => $value::class,
                'message' => mb_scrub($value->getMessage(), 'UTF-8'),
            ],
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            $value instanceof UnitEnum => $value instanceof BackedEnum ? $value->value : $value->name,
            $value instanceof JsonSerializable => $depth >= self::MAX_DEPTH
                ? '[max depth]'
                : $this->sanitizeValue($value->jsonSerialize(), $depth + 1),
            $value instanceof Stringable => mb_scrub((string) $value, 'UTF-8'),
            is_object($value) => '[object '.$value::class.']',
            default => '['.get_debug_type($value).']',
        };
    }
}
