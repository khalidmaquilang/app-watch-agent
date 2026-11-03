<?php

declare(strict_types=1);

namespace AppWatch\Agent\Enums;

/**
 * The recorded cache operations.
 */
enum CacheOperationEnum: string
{
    case Hit = 'hit';
    case Miss = 'miss';
    case Write = 'write';
    case Forget = 'forget';
}
