<?php

declare(strict_types=1);

use AppWatch\Agent\Storage\NormalizeExceptionMessage;

beforeEach(function (): void {
    $this->normalizer = app(NormalizeExceptionMessage::class);
});

it('normalizes messages that only differ by a number equally', function (): void {
    expect($this->normalizer->normalize('User 42 not found'))
        ->toBe($this->normalizer->normalize('User 7 not found'))
        ->toBe('User {n} not found');
});

it('replaces volatile parts with placeholders', function (string $message, string $expected): void {
    expect($this->normalizer->normalize($message))->toBe($expected);
})->with([
    'uuid' => ['Order 0e7c1d9a-6b4f-4c2e-9a1d-7f3e5b2c8a41 missing', 'Order {uuid} missing'],
    'uppercase uuid' => ['Order 0E7C1D9A-6B4F-4C2E-9A1D-7F3E5B2C8A41 missing', 'Order {uuid} missing'],
    'hex of 16+ characters' => ['Token a3f9c0d2e1b4f5a6c7d8 expired', 'Token {hex} expired'],
    'sha256 hash' => ['Hash '.hash('sha256', 'x').' mismatch', 'Hash {hex} mismatch'],
    'email' => ['No account for jane.doe+test@example.co.uk', 'No account for {email}'],
    'double-quoted string' => ['Column "users.email" is invalid', 'Column {str} is invalid'],
    'single-quoted string' => ["Unknown key 'billing_plan'", 'Unknown key {str}'],
    'decimal number' => ['Took 12.5 seconds', 'Took {n} seconds'],
    'several parts' => ['User 42 (bob@example.com) sent "hi" 3 times', 'User {n} ({email}) sent {str} {n} times'],
]);

it('keeps apostrophes inside words', function (): void {
    expect($this->normalizer->normalize("Can't open the user's file"))->toBe("Can't open the user's file");
});

it('keeps hex-looking words shorter than 16 characters', function (): void {
    expect($this->normalizer->normalize('Color abcdef missing'))->toBe('Color abcdef missing');
});

it('trims the message', function (): void {
    expect($this->normalizer->normalize("  Order 1 failed \n"))->toBe('Order {n} failed');
});

it('caps the message to MAX_LENGTH characters', function (): void {
    expect(mb_strlen($this->normalizer->normalize(str_repeat('é', 5000))))->toBe(NormalizeExceptionMessage::MAX_LENGTH);
});

it('returns an empty string for an empty message', function (): void {
    expect($this->normalizer->normalize(''))->toBe('');
});
