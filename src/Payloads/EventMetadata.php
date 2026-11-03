<?php

declare(strict_types=1);

namespace AppWatch\Agent\Payloads;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * The fields every v1 event shares: trace, timestamp, host and deploy.
 */
final readonly class EventMetadata
{
    /**
     * ISO-8601 with microseconds, e.g. 2026-11-03T05:41:03.123456+00:00 (the v1 contract format).
     */
    public const string DATE_FORMAT = 'Y-m-d\TH:i:s.uP';

    /**
     * The shared event fields.
     */
    public function __construct(
        public string $trace_id,
        public DateTimeImmutable $recorded_at,
        public string $host,
        public ?string $deploy,
    ) {}

    /**
     * Capture the metadata for an event of the given trace: now (or the given time), this
     * machine's host name and the configured deploy.
     */
    public static function capture(string $trace_id, ?DateTimeInterface $recorded_at = null): self
    {
        $host_name = gethostname();
        $deploy = null;

        try {
            $configured_deploy = config('app-watch.deploy');
            $deploy = is_string($configured_deploy) && $configured_deploy !== '' ? $configured_deploy : null;
        } catch (Throwable) {
            $deploy = null;
        }

        return new self(
            trace_id: $trace_id,
            recorded_at: DateTimeImmutable::createFromInterface($recorded_at ?? new DateTimeImmutable),
            host: is_string($host_name) && $host_name !== '' ? $host_name : 'unknown',
            deploy: $deploy,
        );
    }

    /**
     * The shared fields as v1 ingest keys, with `recorded_at` in UTC.
     *
     * @return array{trace_id: string, recorded_at: string, host: string, deploy: string|null}
     */
    public function toArray(): array
    {
        return [
            'trace_id' => $this->trace_id,
            'recorded_at' => $this->recorded_at->setTimezone(new DateTimeZone('UTC'))->format(self::DATE_FORMAT),
            'host' => $this->host,
            'deploy' => $this->deploy,
        ];
    }
}
