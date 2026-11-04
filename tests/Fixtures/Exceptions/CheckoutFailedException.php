<?php

declare(strict_types=1);

namespace AppWatch\Agent\Tests\Fixtures\Exceptions;

use RuntimeException;

/**
 * An exception the end-to-end test's route throws, so its exception group is easy to find.
 */
final class CheckoutFailedException extends RuntimeException {}
