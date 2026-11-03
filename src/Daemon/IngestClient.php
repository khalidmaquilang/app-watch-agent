<?php

declare(strict_types=1);

namespace AppWatch\Agent\Daemon;

use Carbon\CarbonImmutable;
use Composer\InstalledVersions;
use DateTimeZone;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Posts events to the monitor's ingest API as v1 batches: the envelope (`sent_at`, `environment`,
 * `events`) is JSON-encoded, gzipped and sent with the environment's bearer token. Batches over
 * the contract's MAX_EVENTS_PER_BATCH are split. A network error, 408, 429 or 5xx stops at that
 * batch and hands it (and every later one) back to be retried; any other non-2xx status (401,
 * 422, ...) rejects that batch for good, because retrying cannot help.
 */
final class IngestClient
{
    /**
     * The most events the v1 contract accepts in one batch.
     */
    public const int MAX_EVENTS_PER_BATCH = 1000;

    /**
     * How long one POST may take, in seconds.
     */
    public const int TIMEOUT_SECONDS = 10;

    /**
     * The longest Retry-After honoured, in seconds, so a bogus header cannot stall delivery.
     */
    public const int MAX_RETRY_AFTER_SECONDS = 600;

    /**
     * The version reported when the agent package version is unknown.
     */
    public const string UNKNOWN_VERSION = 'unknown';

    /**
     * The client's collaborators.
     */
    public function __construct(
        private readonly Repository $config,
        private readonly Factory $http,
        private readonly Application $app,
    ) {}

    /**
     * Post the events in batches of at most MAX_EVENTS_PER_BATCH, stopping at the first batch
     * that should be retried. Each POST may take at most the given number of seconds.
     *
     * @param  array<int, array<string, mixed>>  $events
     */
    public function send(array $events, int $timeout_seconds = self::TIMEOUT_SECONDS): IngestResult
    {
        $batches = array_chunk($events, self::MAX_EVENTS_PER_BATCH);
        $delivered_count = 0;
        $rejected_count = 0;
        $rejected_status = null;

        foreach ($batches as $index => $batch) {
            try {
                $response = $this->post($batch, $timeout_seconds);
            } catch (Throwable $exception) {
                return new IngestResult(
                    delivered_count: $delivered_count,
                    rejected_count: $rejected_count,
                    rejected_status: $rejected_status,
                    pending: array_merge(...array_slice($batches, $index)),
                    failure: $exception->getMessage(),
                );
            }

            $status = $response->status();

            if ($response->successful()) {
                $delivered_count += count($batch);

                continue;
            }

            if ($status === 408 || $status === 429 || $status >= 500) {
                return new IngestResult(
                    delivered_count: $delivered_count,
                    rejected_count: $rejected_count,
                    rejected_status: $rejected_status,
                    pending: array_merge(...array_slice($batches, $index)),
                    retry_after_seconds: $this->retryAfterSeconds($response),
                    failure: "HTTP {$status}",
                );
            }

            $rejected_count += count($batch);
            $rejected_status = $status;
        }

        return new IngestResult(
            delivered_count: $delivered_count,
            rejected_count: $rejected_count,
            rejected_status: $rejected_status,
        );
    }

    /**
     * The ingest endpoint: "{monitor_url}/api/{api_version}/ingest".
     */
    public function url(): string
    {
        $monitor_url = $this->config->get('app-watch.monitor_url');
        $api_version = $this->config->get('app-watch.api_version', 'v1');

        return rtrim(is_string($monitor_url) ? $monitor_url : '', '/')
            .'/api/'.(is_string($api_version) && $api_version !== '' ? $api_version : 'v1').'/ingest';
    }

    /**
     * The v1 batch envelope for the events.
     *
     * @param  array<int, array<string, mixed>>  $events
     * @return array{sent_at: string, environment: array{php_version: string, laravel_version: string, agent_version: string, host: string}, events: array<int, array<string, mixed>>}
     */
    public function envelope(array $events): array
    {
        $host = gethostname();

        return [
            'sent_at' => CarbonImmutable::now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP'),
            'environment' => [
                'php_version' => PHP_VERSION,
                'laravel_version' => $this->app->version(),
                'agent_version' => $this->agentVersion(),
                'host' => is_string($host) && $host !== '' ? $host : 'unknown',
            ],
            'events' => array_values($events),
        ];
    }

    /**
     * POST one batch, gzipped, with the bearer token.
     *
     * @param  array<int, array<string, mixed>>  $events
     *
     * @throws Throwable
     */
    private function post(array $events, int $timeout_seconds): Response
    {
        $token = $this->config->get('app-watch.token');
        $body = gzencode(json_encode(
            $this->envelope($events),
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return $this->http
            ->withToken(is_string($token) ? $token : '')
            ->acceptJson()
            ->withHeaders(['Content-Encoding' => 'gzip'])
            ->withoutRedirecting()
            ->timeout(max(1, $timeout_seconds))
            ->withBody($body === false ? '' : $body, 'application/json')
            ->post($this->url());
    }

    /**
     * The response's Retry-After in seconds (delta-seconds or an HTTP date), capped at
     * MAX_RETRY_AFTER_SECONDS; null when absent or invalid.
     */
    private function retryAfterSeconds(Response $response): ?int
    {
        $retry_after = trim($response->header('Retry-After'));

        if ($retry_after === '') {
            return null;
        }

        if (ctype_digit($retry_after)) {
            $seconds = (int) $retry_after;
        } else {
            $retry_at = strtotime($retry_after);

            if ($retry_at === false) {
                return null;
            }

            $seconds = $retry_at - CarbonImmutable::now()->getTimestamp();
        }

        return max(0, min($seconds, self::MAX_RETRY_AFTER_SECONDS));
    }

    /**
     * The installed agent package version (at most 50 characters, the contract limit).
     */
    private function agentVersion(): string
    {
        try {
            $version = InstalledVersions::isInstalled('app-watch/agent')
                ? InstalledVersions::getPrettyVersion('app-watch/agent')
                : null;
        } catch (Throwable) {
            $version = null;
        }

        return substr(is_string($version) && $version !== '' ? $version : self::UNKNOWN_VERSION, 0, 50);
    }
}
