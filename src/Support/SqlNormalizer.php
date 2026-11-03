<?php

declare(strict_types=1);

namespace AppWatch\Agent\Support;

use Throwable;

/**
 * Turns SQL into its normalized form, so the same query with different values aggregates under one
 * `sql_hash` and no literal value ever leaves the monitored app: quoted strings and numbers become
 * `?`, `IN (?, ?, ?)` becomes `IN (?)` and whitespace collapses to single spaces. Quoted identifiers
 * ("orders", `orders`) are kept.
 */
final readonly class SqlNormalizer
{
    /**
     * The longest normalized SQL kept (the v1 contract limit); longer SQL is cut before hashing.
     */
    public const int MAX_SQL_LENGTH = 10000;

    /**
     * One token per match, tried in order: a single-quoted string (with '' and \ escapes, or
     * unterminated up to the end), a double-quoted or backtick identifier, a hex or decimal number
     * that is not part of a word, or a run of whitespace.
     */
    private const string TOKEN_PATTERN = <<<'REGEX'
        /
          '(?:[^'\\]|\\.|'')*+(?:'|$)
        | "(?:[^"]|"")*+"
        | `(?:[^`]|``)*+`
        | (?<![\w$])0x[0-9a-f]+\b
        | (?<![\w$])\d+(?:\.\d+)?(?:e[+-]?\d+)?\b
        | \s+
        /xis
        REGEX;

    /**
     * An IN list made only of placeholders, after the literals became `?`.
     */
    private const string IN_LIST_PATTERN = '/\b(in) ?\( ?\?(?: ?, ?\?)*+ ?\)/i';

    /**
     * The normalized SQL, or null when it cannot be normalized safely (the query must then not be
     * recorded, because its literals could not be removed).
     */
    public function normalize(string $sql): ?string
    {
        try {
            $tokenized = preg_replace_callback(
                self::TOKEN_PATTERN,
                static fn (array $match): string => match ($match[0][0]) {
                    '"', '`' => $match[0],
                    ' ', "\t", "\n", "\r", "\f", "\v" => ' ',
                    default => '?',
                },
                $sql,
            );

            if (! is_string($tokenized)) {
                return null;
            }

            $collapsed = preg_replace(self::IN_LIST_PATTERN, '$1 (?)', $tokenized);

            if (! is_string($collapsed)) {
                return null;
            }

            return mb_scrub(mb_substr(trim($collapsed), 0, self::MAX_SQL_LENGTH));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The `sql_hash` of normalized SQL: its SHA-1 (an aggregation key, not a security hash), via
     * hash() because the monitor's arch security preset forbids sha1().
     */
    public function hash(string $normalized_sql): string
    {
        return hash('sha1', $normalized_sql);
    }
}
