<?php

declare(strict_types=1);

namespace AppWatch\Agent\Storage;

/**
 * The fingerprint that groups occurrences of the same exception.
 */
final class FingerprintException
{
    /**
     * The message normalizer.
     */
    public function __construct(
        private readonly NormalizeExceptionMessage $normalize_exception_message,
    ) {}

    /**
     * sha256 of `class|file|line|normalized message` (64 hex characters).
     */
    public function fingerprint(string $class, string $file, int $line, string $message): string
    {
        return hash('sha256', implode('|', [
            $class,
            $file,
            $line,
            $this->normalize_exception_message->normalize($message),
        ]));
    }
}
