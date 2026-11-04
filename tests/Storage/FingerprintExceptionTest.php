<?php

declare(strict_types=1);

use AppWatch\Agent\Storage\FingerprintException;

beforeEach(function (): void {
    $this->fingerprinter = app(FingerprintException::class);
});

it('hashes class, file, line and the normalized message with sha256', function (): void {
    expect($this->fingerprinter->fingerprint('RuntimeException', '/app/Order.php', 10, 'Order 1 failed'))
        ->toBe(hash('sha256', 'RuntimeException|/app/Order.php|10|Order {n} failed'));
});

it('gives the same fingerprint to messages that only differ by ids', function (): void {
    expect($this->fingerprinter->fingerprint('RuntimeException', '/app/Order.php', 10, 'Order 1 failed'))
        ->toBe($this->fingerprinter->fingerprint('RuntimeException', '/app/Order.php', 10, 'Order 2 failed'));
});

it('gives a different fingerprint when any part differs', function (string $class, string $file, int $line, string $message): void {
    expect($this->fingerprinter->fingerprint($class, $file, $line, $message))
        ->not->toBe($this->fingerprinter->fingerprint('RuntimeException', '/app/Order.php', 10, 'Order 1 failed'));
})->with([
    'class' => ['LogicException', '/app/Order.php', 10, 'Order 1 failed'],
    'file' => ['RuntimeException', '/app/Invoice.php', 10, 'Order 1 failed'],
    'line' => ['RuntimeException', '/app/Order.php', 11, 'Order 1 failed'],
    'message' => ['RuntimeException', '/app/Order.php', 10, 'Order 1 shipped'],
]);
