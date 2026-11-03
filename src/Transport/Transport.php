<?php

declare(strict_types=1);

namespace AppWatch\Agent\Transport;

use AppWatch\Agent\Payloads\EventPayload;

/**
 * Hands a flushed trace's events on to wherever they are shipped from. Implementations never
 * throw into the host app.
 */
interface Transport
{
    /**
     * Send the events; failures are swallowed.
     *
     * @param  array<int, EventPayload>  $events
     */
    public function send(array $events): void;
}
