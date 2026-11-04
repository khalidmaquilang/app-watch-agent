<?php

declare(strict_types=1);

namespace AppWatch\Agent\Support;

use Illuminate\Contracts\Config\Repository;
use Throwable;

/**
 * Whether the agent has everything it needs to record: it is enabled, knows its environment id
 * and has a telemetry database connection. Recording, the transport and tracing all key off
 * this, so the agent stays silent until it is configured.
 */
final readonly class AgentConfiguration
{
    /**
     * Read the agent's settings from the app's config.
     */
    public function __construct(
        private Repository $config,
    ) {}

    /**
     * True when `enabled` is on, `environment_id` is a positive integer and the configured
     * connection exists. Never throws.
     */
    public function isConfigured(): bool
    {
        try {
            return (bool) $this->config->get('app-watch.enabled', true)
                && $this->environmentId() !== null
                && $this->hasConnection();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The configured environment id, or null unless it is a positive integer (or a string of
     * digits, as a published config may hold).
     */
    public function environmentId(): ?int
    {
        $environment_id = $this->config->get('app-watch.environment_id');

        if (! is_int($environment_id) && ! is_string($environment_id)) {
            return null;
        }

        $validated = filter_var($environment_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($validated) ? $validated : null;
    }

    /**
     * Whether the configured connection is defined in `database.connections`.
     */
    private function hasConnection(): bool
    {
        $connection = $this->config->get('app-watch.connection');

        return is_string($connection)
            && $connection !== ''
            && is_array($this->config->get('database.connections.'.$connection));
    }
}
