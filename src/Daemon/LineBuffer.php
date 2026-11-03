<?php

declare(strict_types=1);

namespace AppWatch\Agent\Daemon;

use JsonException;

/**
 * Reassembles the newline-delimited JSON one app connection sends (lines may arrive split across
 * reads) and decodes each complete line into an event. Malformed lines (invalid JSON, not an
 * object, no string `type`, or longer than MAX_LINE_BYTES) are skipped and counted.
 */
final class LineBuffer
{
    /**
     * The longest line kept; a longer one is discarded (and counted) so one connection cannot
     * grow the daemon's memory without bound.
     */
    public const int MAX_LINE_BYTES = 1_048_576;

    /**
     * The bytes received after the last complete line.
     */
    private string $buffer = '';

    /**
     * Whether the rest of an over-long line is being skipped until its newline.
     */
    private bool $discarding = false;

    /**
     * Lines skipped because they could not be decoded into an event.
     */
    private int $malformed_count = 0;

    /**
     * Append a chunk read from the connection and return the events of every line it completes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function push(string $chunk): array
    {
        $this->buffer .= $chunk;
        $events = [];
        $offset = 0;

        while (($newline = strpos($this->buffer, "\n", $offset)) !== false) {
            $line = substr($this->buffer, $offset, $newline - $offset);
            $offset = $newline + 1;

            if ($this->discarding) {
                $this->discarding = false;

                continue;
            }

            $event = $this->decode($line);

            if ($event !== null) {
                $events[] = $event;
            }
        }

        $this->buffer = substr($this->buffer, $offset);

        if (strlen($this->buffer) > self::MAX_LINE_BYTES) {
            if (! $this->discarding) {
                $this->malformed_count++;
            }

            $this->discarding = true;
            $this->buffer = '';
        }

        return $events;
    }

    /**
     * The connection closed: decode a last line that had no trailing newline.
     *
     * @return array<int, array<string, mixed>>
     */
    public function finish(): array
    {
        $line = $this->buffer;
        $discarding = $this->discarding;

        $this->buffer = '';
        $this->discarding = false;

        if ($discarding) {
            return [];
        }

        $event = $this->decode($line);

        return $event === null ? [] : [$event];
    }

    /**
     * Lines skipped because they could not be decoded into an event.
     */
    public function malformedCount(): int
    {
        return $this->malformed_count;
    }

    /**
     * The line as an event, or null (counted as malformed unless the line is blank).
     *
     * @return array<string, mixed>|null
     */
    private function decode(string $line): ?array
    {
        if (trim($line) === '') {
            return null;
        }

        try {
            $event = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->malformed_count++;

            return null;
        }

        if (! is_array($event) || array_is_list($event) || ! is_string($event['type'] ?? null)) {
            $this->malformed_count++;

            return null;
        }

        /** @var array<string, mixed> $event */
        return $event;
    }
}
