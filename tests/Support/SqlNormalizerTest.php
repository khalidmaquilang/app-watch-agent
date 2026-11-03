<?php

declare(strict_types=1);

use AppWatch\Agent\Support\SqlNormalizer;

beforeEach(function (): void {
    $this->normalizer = new SqlNormalizer;
});

it('normalizes SQL', function (string $sql, string $expected): void {
    expect($this->normalizer->normalize($sql))->toBe($expected);
})->with([
    'numbers and quoted strings' => ["select * from users where id = 5 and email = 'a@b.c'", 'select * from users where id = ? and email = ?'],
    'decimals, exponents and hex' => ['select * from prices where amount > 10.50 and ratio < 1e-3 and flags = 0xFF', 'select * from prices where amount > ? and ratio < ? and flags = ?'],
    'escaped quotes in strings' => ["select * from notes where body = 'it''s' or body = 'say \\'hi\\''", 'select * from notes where body = ? or body = ?'],
    'an empty string' => ["update users set name = '' where id = 1", 'update users set name = ? where id = ?'],
    'an unterminated string' => ["select * from users where name = 'secret value", 'select * from users where name = ?'],
    'quoted identifiers are kept' => ['select "orders"."id2", `items`.`1st` from "orders" where "orders"."id" = ? limit 1', 'select "orders"."id2", `items`.`1st` from "orders" where "orders"."id" = ? limit ?'],
    'digits inside identifiers are kept' => ['select t1.col2 from table_3 t1', 'select t1.col2 from table_3 t1'],
    'IN lists of literals' => ['select * from users where id in (1, 2, 3)', 'select * from users where id in (?)'],
    'IN lists of placeholders' => ['select * from users where id IN (?,?, ?) and role not in (?)', 'select * from users where id IN (?) and role not in (?)'],
    'IN lists of strings' => ["select * from users where role in ('admin', 'owner')", 'select * from users where role in (?)'],
    'subqueries in IN are kept' => ['select * from users where id in (select user_id from posts where id = 4)', 'select * from users where id in (select user_id from posts where id = ?)'],
    'whitespace collapses' => ["  select *\n\tfrom   users\r\n where id = ?  ", 'select * from users where id = ?'],
]);

it('normalizes the same query with different values to the same SQL and hash', function (): void {
    $first = (string) $this->normalizer->normalize("select * from users where id = 5 and email = 'a@b.c'");
    $second = (string) $this->normalizer->normalize("select * from users where id = 9 and email = 'x@y.z'");

    expect($first)->toBe($second)
        ->and($this->normalizer->hash($first))->toBe($this->normalizer->hash($second))->toBe(sha1($first))
        ->and($first)->not->toContain('5')->not->toContain('a@b.c');
});

it('normalizes IN lists of any length equally', function (): void {
    expect($this->normalizer->normalize('select * from users where id in (1, 2, 3)'))
        ->toBe($this->normalizer->normalize('select * from users where id in (4)'));
});

it('truncates very long SQL to the contract limit, consistently', function (): void {
    $sql = 'select '.implode(', ', array_fill(0, 3000, '"column"')).' from "wide_table"';

    $normalized = (string) $this->normalizer->normalize($sql);

    expect(mb_strlen($normalized))->toBe(SqlNormalizer::MAX_SQL_LENGTH)
        ->and($normalized)->toBe($this->normalizer->normalize($sql.' where "id" = 7'))
        ->and($this->normalizer->hash($normalized))->toBe(sha1($normalized));
});

it('keeps multibyte characters valid when truncating', function (): void {
    $normalized = (string) $this->normalizer->normalize('select "'.str_repeat('é', SqlNormalizer::MAX_SQL_LENGTH).'" from t');

    expect(mb_strlen($normalized))->toBe(SqlNormalizer::MAX_SQL_LENGTH)
        ->and(mb_check_encoding($normalized, 'UTF-8'))->toBeTrue();
});
