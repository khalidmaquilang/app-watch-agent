<?php

declare(strict_types=1);

namespace AppWatch\Agent\Daemon;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;
use JsonException;

/**
 * Keeps batches the monitor could not take on disk until it can: each batch is one gzipped JSON
 * list of events in `app-watch.daemon.spool_path`, named "{timestamp}-{uuid}.json.gz" so names
 * sort oldest first. The spool never grows past `app-watch.daemon.spool_max_bytes`: the oldest
 * files are deleted (and their events counted as dropped) to make room. Files are written to a
 * hidden temporary name and renamed, so a crash never leaves a half-written batch behind; a
 * corrupt file found anyway is deleted and counted. Nothing here throws: a disk that is full or
 * a directory that is not writable makes write() return false, with the reason in lastError().
 */
final class Spool
{
    /**
     * The spool's default size cap: 100 MB.
     */
    public const int MAX_BYTES = 100 * 1024 * 1024;

    /**
     * The extension of a spooled batch.
     */
    public const string EXTENSION = '.json.gz';

    /**
     * The JSON flags the events are written with, the same ones the ingest client posts with, so
     * a replayed batch carries exactly the events that were spooled.
     */
    public const int JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * The last timestamp (microseconds since the epoch) used in a file name; names stay strictly
     * increasing even when two batches are spooled within the same microsecond.
     */
    private int $last_timestamp = 0;

    /**
     * Spooled events deleted to keep the spool under its size cap.
     */
    private int $evicted_count = 0;

    /**
     * Corrupt spool files deleted.
     */
    private int $corrupt_count = 0;

    /**
     * Why the last write failed; null when it succeeded.
     */
    private ?string $last_error = null;

    /**
     * The spool's collaborators.
     */
    public function __construct(
        private readonly Repository $config,
    ) {}

    /**
     * Write the events as a new spooled batch, deleting the oldest batches past the size cap.
     * Returns false (with the reason in lastError()) when the batch could not be written.
     *
     * @param  array<int, array<string, mixed>>  $events
     */
    public function write(array $events): bool
    {
        $this->last_error = null;

        if ($events === []) {
            return true;
        }

        $contents = $this->encode($events);

        if ($contents === null) {
            return false;
        }

        $directory = $this->path();

        if (! $this->ensureDirectory($directory)) {
            return false;
        }

        $size = strlen($contents);
        $max_bytes = $this->maxBytes();

        if ($size > $max_bytes) {
            $this->last_error = "the batch ({$size} bytes) is larger than the spool limit ({$max_bytes} bytes)";

            return false;
        }

        $this->makeRoom($max_bytes - $size);

        return $this->store($directory.DIRECTORY_SEPARATOR.$this->nextFileName(), $contents);
    }

    /**
     * Replace a spooled batch's events (after part of it was delivered), keeping its place in line.
     *
     * @param  array<int, array<string, mixed>>  $events
     */
    public function replace(string $path, array $events): bool
    {
        $this->last_error = null;

        if ($events === []) {
            $this->delete($path);

            return true;
        }

        $contents = $this->encode($events);

        return $contents !== null && $this->store($path, $contents);
    }

    /**
     * The oldest readable batch, or null when the spool is empty. Corrupt files met on the way are
     * deleted and counted.
     */
    public function oldest(): ?SpooledBatch
    {
        foreach ($this->files() as $path) {
            $events = $this->read($path);

            if ($events !== null) {
                return new SpooledBatch($path, $events);
            }

            $this->delete($path);
            $this->corrupt_count++;
        }

        return null;
    }

    /**
     * Delete a spooled batch.
     */
    public function delete(string $path): void
    {
        if (is_file($path)) {
            set_error_handler(static fn (): bool => true);

            try {
                unlink($path);
            } finally {
                restore_error_handler();
            }
        }
    }

    /**
     * The spooled batch files, oldest first.
     *
     * @return array<int, string>
     */
    public function files(): array
    {
        $files = glob($this->path().DIRECTORY_SEPARATOR.'*'.self::EXTENSION);

        if ($files === false) {
            return [];
        }

        $files = array_values(array_filter($files, is_file(...)));
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * The total size of the spooled batches, in bytes.
     */
    public function totalBytes(): int
    {
        clearstatcache();

        return array_sum(array_map(static fn (string $path): int => (int) filesize($path), $this->files()));
    }

    /**
     * Whether nothing is spooled.
     */
    public function isEmpty(): bool
    {
        return $this->files() === [];
    }

    /**
     * Spooled events deleted to keep the spool under its size cap.
     */
    public function evictedCount(): int
    {
        return $this->evicted_count;
    }

    /**
     * Corrupt spool files deleted.
     */
    public function corruptCount(): int
    {
        return $this->corrupt_count;
    }

    /**
     * Why the last write failed; null when it succeeded.
     */
    public function lastError(): ?string
    {
        return $this->last_error;
    }

    /**
     * The configured spool directory, without a trailing separator.
     */
    public function path(): string
    {
        $path = $this->config->get('app-watch.daemon.spool_path');

        if (! is_string($path) || $path === '') {
            $path = storage_path('app/app-watch/spool');
        }

        return rtrim($path, '/\\');
    }

    /**
     * The configured size cap in bytes (at least 1).
     */
    public function maxBytes(): int
    {
        $max_bytes = $this->config->get('app-watch.daemon.spool_max_bytes', self::MAX_BYTES);

        return is_numeric($max_bytes) ? max(1, (int) $max_bytes) : self::MAX_BYTES;
    }

    /**
     * The events as a gzipped JSON list, or null (with lastError() set) when they cannot be encoded.
     *
     * @param  array<int, array<string, mixed>>  $events
     */
    private function encode(array $events): ?string
    {
        try {
            $contents = gzencode(json_encode(array_values($events), self::JSON_FLAGS));
        } catch (JsonException $exception) {
            $this->last_error = 'the events could not be encoded: '.$exception->getMessage();

            return null;
        }

        if ($contents === false) {
            $this->last_error = 'the events could not be compressed';

            return null;
        }

        return $contents;
    }

    /**
     * A spooled file's events, or null when the file is unreadable or corrupt.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function read(string $path): ?array
    {
        set_error_handler(static fn (): bool => true);

        try {
            $contents = file_get_contents($path);
            $json = is_string($contents) ? gzdecode($contents) : false;
        } finally {
            restore_error_handler();
        }

        if (! is_string($json)) {
            return null;
        }

        try {
            $events = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($events) || $events === [] || ! array_is_list($events)) {
            return null;
        }

        foreach ($events as $event) {
            if (! is_array($event) || array_is_list($event)) {
                return null;
            }
        }

        /** @var array<int, array<string, mixed>> $events */
        return $events;
    }

    /**
     * Create the spool directory if needed; false (with lastError() set) when it is not writable.
     */
    private function ensureDirectory(string $directory): bool
    {
        set_error_handler(static fn (): bool => true);

        try {
            $exists = is_dir($directory) || mkdir($directory, 0o755, true) || is_dir($directory);
        } finally {
            restore_error_handler();
        }

        if (! $exists || ! is_writable($directory)) {
            $this->last_error = "the spool directory {$directory} is not writable";

            return false;
        }

        return true;
    }

    /**
     * Delete the oldest batches until at most the given number of bytes is spooled.
     */
    private function makeRoom(int $allowed_bytes): void
    {
        clearstatcache();

        $files = $this->files();
        $sizes = array_map(static fn (string $path): int => (int) filesize($path), $files);
        $total = array_sum($sizes);

        foreach ($files as $index => $path) {
            if ($total <= $allowed_bytes) {
                return;
            }

            $events = $this->read($path);

            if ($events === null) {
                $this->corrupt_count++;
            } else {
                $this->evicted_count += count($events);
            }

            $this->delete($path);
            $total -= $sizes[$index];
        }
    }

    /**
     * Write the contents to a hidden temporary file and rename it into place.
     */
    private function store(string $path, string $contents): bool
    {
        $temporary_path = dirname($path).DIRECTORY_SEPARATOR.'.'.basename($path).'.tmp';
        $error = null;

        set_error_handler(static function (int $error_number, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });

        try {
            $written = file_put_contents($temporary_path, $contents) === strlen($contents)
                && rename($temporary_path, $path);
        } finally {
            restore_error_handler();
        }

        if (! $written) {
            $this->delete($temporary_path);
            $this->last_error = 'the batch could not be written'.(is_string($error) ? ": {$error}" : ' (is the disk full?)');

            return false;
        }

        return true;
    }

    /**
     * A new file name: "{timestamp}-{uuid}.json.gz", the timestamp in zero-padded microseconds so
     * that names sort oldest first, and strictly after every batch already spooled.
     */
    private function nextFileName(): string
    {
        $now = (int) CarbonImmutable::now()->format('Uu');

        if ($this->last_timestamp === 0) {
            $newest = $this->files();
            $newest_name = $newest === [] ? '' : basename((string) end($newest));
            $this->last_timestamp = (int) strstr($newest_name, '-', true);
        }

        $this->last_timestamp = max($now, $this->last_timestamp + 1);

        return sprintf('%020d', $this->last_timestamp).'-'.Str::uuid()->toString().self::EXTENSION;
    }
}
