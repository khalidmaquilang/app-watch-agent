<?php

declare(strict_types=1);

namespace AppWatch\Agent\Payloads;

use AppWatch\Agent\Enums\TelemetryTypeEnum;

/**
 * One recorded event, serializable to the monitor's v1 ingest contract.
 */
interface EventPayload
{
    /**
     * The event's `type` discriminator.
     */
    public function type(): TelemetryTypeEnum;

    /**
     * The trace the event belongs to.
     */
    public function traceId(): string;

    /**
     * The event as a v1 ingest event (common fields first, then the type's own fields).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
