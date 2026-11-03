<?php

declare(strict_types=1);

namespace AppWatch\Agent\Payloads;

/**
 * One stack frame of a recorded exception.
 */
final readonly class ExceptionTraceFrame
{
    /**
     * The frame location and callee.
     */
    public function __construct(
        public ?string $file,
        public ?int $line,
        public string $function,
        public ?string $class,
    ) {}

    /**
     * The frame as a v1 ingest trace frame.
     *
     * @return array{file: string|null, line: int|null, function: string, class: string|null}
     */
    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'line' => $this->line,
            'function' => $this->function,
            'class' => $this->class,
        ];
    }
}
