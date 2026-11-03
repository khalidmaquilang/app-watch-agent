<?php

declare(strict_types=1);

namespace AppWatch\Agent\Tests\Fixtures\Exceptions;

use RuntimeException;

/**
 * An exception the tests add to the handler's `dontReport` list.
 */
final class IgnoredException extends RuntimeException {}
