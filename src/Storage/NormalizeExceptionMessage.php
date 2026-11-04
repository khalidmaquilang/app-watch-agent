<?php

declare(strict_types=1);

namespace AppWatch\Agent\Storage;

/**
 * Replaces the volatile parts of an exception message so messages that only differ by ids group together.
 */
final class NormalizeExceptionMessage
{
    /**
     * The longest normalized message, in characters.
     */
    public const int MAX_LENGTH = 1000;

    /**
     * The volatile parts of a message and their placeholders, applied in this order: UUIDs and emails
     * first (they contain hex and digits), then quoted strings, then long hex strings, then numbers.
     * Quotes only count when they are not inside a word, so apostrophes ("can't") are kept.
     *
     * @var array<string, string>
     */
    public const array PATTERNS = [
        '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i' => '{uuid}',
        '/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}/' => '{email}',
        '/(?<!\w)"[^"]*"(?!\w)|(?<!\w)\'[^\']*\'(?!\w)/' => '{str}',
        '/\b[0-9a-f]{16,}\b/i' => '{hex}',
        '/\d+(?:\.\d+)?/' => '{n}',
    ];

    /**
     * Replace UUIDs, emails, quoted strings, hex strings of 16+ characters and numbers with
     * placeholders, trim the result and cap it to MAX_LENGTH characters.
     */
    public function normalize(string $message): string
    {
        $normalized = preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $message) ?? $message;

        return mb_substr(trim($normalized), 0, self::MAX_LENGTH);
    }
}
