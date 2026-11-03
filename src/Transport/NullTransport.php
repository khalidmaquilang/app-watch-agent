<?php

declare(strict_types=1);

namespace AppWatch\Agent\Transport;

use AppWatch\Agent\Payloads\EventPayload;

/**
 * Discards every event. Used while the agent is disabled or has no token.
 */
final readonly class NullTransport implements Transport
{
    /**
     * Discard the events.
     *
     * @param  array<int, EventPayload>  $events
     */
    public function send(array $events): void {}
}
