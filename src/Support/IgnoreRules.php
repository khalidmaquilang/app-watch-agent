<?php

declare(strict_types=1);

namespace AppWatch\Agent\Support;

use AppWatch\Agent\Enums\LogLevelEnum;
use AppWatch\Agent\Payloads\EventPayload;
use AppWatch\Agent\Payloads\JobEventPayload;
use AppWatch\Agent\Payloads\LogEventPayload;
use AppWatch\Agent\Payloads\QueryEventPayload;
use AppWatch\Agent\Payloads\RequestEventPayload;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;

/**
 * The configured `ignore` rules (paths, job classes, query regexes) and the `log_level` threshold.
 */
final readonly class IgnoreRules
{
    /**
     * Read the rules from the app-watch config on every check, so runtime config changes apply.
     */
    public function __construct(
        private Repository $config,
    ) {}

    /**
     * Whether the event must not be recorded.
     */
    public function shouldIgnore(EventPayload $event): bool
    {
        return match (true) {
            $event instanceof RequestEventPayload => $this->shouldIgnorePath($event->path),
            $event instanceof JobEventPayload => $this->shouldIgnoreJob($event->job_class),
            $event instanceof QueryEventPayload => $this->shouldIgnoreQuery($event->sql),
            $event instanceof LogEventPayload => $this->shouldIgnoreLogLevel($event->level),
            default => false,
        };
    }

    /**
     * Whether the request path matches an `ignore.paths` glob; paths compare with a leading slash.
     */
    public function shouldIgnorePath(string $path): bool
    {
        $normalized_path = '/'.ltrim($path, '/');

        foreach ($this->patterns('paths') as $pattern) {
            if (Str::is('/'.ltrim($pattern, '/'), $normalized_path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the job class matches an `ignore.jobs` entry (exact class name or glob).
     */
    public function shouldIgnoreJob(string $job_class): bool
    {
        $normalized_class = ltrim($job_class, '\\');

        foreach ($this->patterns('jobs') as $pattern) {
            if (Str::is(ltrim($pattern, '\\'), $normalized_class)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the SQL matches an `ignore.queries` regex; an invalid regex never matches.
     */
    public function shouldIgnoreQuery(string $sql): bool
    {
        foreach ($this->patterns('queries') as $pattern) {
            if (@preg_match($pattern, $sql) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the level is below the configured `log_level` (an unknown threshold means `warning`).
     */
    public function shouldIgnoreLogLevel(LogLevelEnum $level): bool
    {
        $configured_level = $this->config->get('app-watch.log_level');
        $threshold = is_string($configured_level)
            ? (LogLevelEnum::tryFrom(strtolower($configured_level)) ?? LogLevelEnum::Warning)
            : LogLevelEnum::Warning;

        return ! $level->isAtLeast($threshold);
    }

    /**
     * The non-empty string entries of an `ignore.*` list.
     *
     * @return array<int, string>
     */
    private function patterns(string $key): array
    {
        $patterns = $this->config->get('app-watch.ignore.'.$key, []);

        if (! is_array($patterns)) {
            return [];
        }

        return array_values(array_filter(
            $patterns,
            static fn (mixed $pattern): bool => is_string($pattern) && $pattern !== '',
        ));
    }
}
