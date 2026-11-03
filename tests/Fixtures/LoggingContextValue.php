<?php

declare(strict_types=1);

namespace AppWatch\Agent\Tests\Fixtures;

use Illuminate\Support\Facades\Log;
use Stringable;

/**
 * A log context value that logs again (with itself in the context) whenever it is turned into a
 * string, so recording it without a recursion guard would never end.
 */
final class LoggingContextValue implements Stringable
{
    /**
     * How many times it was turned into a string.
     */
    public int $calls = 0;

    /**
     * Log again, then return a fixed string.
     */
    public function __toString(): string
    {
        $this->calls++;

        if ($this->calls < 50) {
            Log::error('Logged while recording', ['value' => $this]);
        }

        return 'logging value';
    }
}
