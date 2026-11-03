<?php

declare(strict_types=1);

namespace AppWatch\Agent\Listeners;

use AppWatch\Agent\Enums\CacheOperationEnum;
use AppWatch\Agent\Payloads\CacheEventPayload;
use AppWatch\Agent\Payloads\EventMetadata;
use AppWatch\Agent\Recorder;
use AppWatch\Agent\Support\TraceContext;
use Illuminate\Cache\Events\CacheEvent;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Throwable;

/**
 * Records the cache hits, misses, writes and forgets of the current trace: the store name, the key
 * (cut to 255 bytes) and the operation. Cached values are never read. Events outside a trace are
 * not recorded. Bound as a singleton, so cache use while recording an event (i.e. by the agent
 * itself) is never recorded.
 */
final class RecordCacheListener
{
    /**
     * The longest key kept, in bytes (the v1 contract limit).
     */
    public const int MAX_KEY_LENGTH = 255;

    /**
     * Whether a cache event is being recorded right now.
     */
    private bool $is_recording = false;

    /**
     * The agent services the listener uses.
     */
    public function __construct(
        private readonly Recorder $recorder,
        private readonly TraceContext $trace_context,
    ) {}

    /**
     * Record the cache operation.
     */
    public function handle(CacheEvent $event): void
    {
        if ($this->is_recording) {
            return;
        }

        $this->is_recording = true;

        try {
            $operation = match (true) {
                $event instanceof CacheHit => CacheOperationEnum::Hit,
                $event instanceof CacheMissed => CacheOperationEnum::Miss,
                $event instanceof KeyWritten => CacheOperationEnum::Write,
                $event instanceof KeyForgotten => CacheOperationEnum::Forget,
                default => null,
            };
            $trace_id = $this->trace_context->traceId();

            if ($operation === null || $trace_id === null || ! $this->trace_context->isSampled() || ! $this->recorder->isEnabled()) {
                return;
            }

            $store = is_string($event->storeName) && $event->storeName !== '' ? $event->storeName : 'default';

            $this->recorder->record(new CacheEventPayload(
                metadata: EventMetadata::capture($trace_id),
                store: mb_strcut(mb_scrub($store, 'UTF-8'), 0, 255, 'UTF-8'),
                key: mb_strcut(mb_scrub((string) $event->key, 'UTF-8'), 0, self::MAX_KEY_LENGTH, 'UTF-8'),
                operation: $operation,
                duration_ms: null,
            ));
        } catch (Throwable) {
            return;
        } finally {
            $this->is_recording = false;
        }
    }
}
