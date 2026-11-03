<?php

declare(strict_types=1);

namespace AppWatch\Agent\Daemon;

/**
 * One batch read back from the disk spool: its file and its events, exactly as they were spooled.
 */
final readonly class SpooledBatch
{
    /**
     * The batch's file and events.
     *
     * @param  array<int, array<string, mixed>>  $events
     */
    public function __construct(
        public string $path,
        public array $events,
    ) {}
}
