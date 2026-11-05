<?php

declare(strict_types=1);

namespace AppWatch\Agent\Tests\Fixtures\Exceptions;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\ServiceProvider;

/**
 * Swaps the exception handler for a wrapper while providers register, like Collision does in the
 * console (which it skips under unit tests, so the tests need this stand-in).
 */
final class WrapExceptionHandlerServiceProvider extends ServiceProvider
{
    /**
     * Resolve the app's handler and rebind the contract to a wrapper around it.
     */
    public function register(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);

        $this->app->singleton(ExceptionHandler::class, static fn (): ExceptionHandler => new WrappingExceptionHandler($handler));
    }
}
