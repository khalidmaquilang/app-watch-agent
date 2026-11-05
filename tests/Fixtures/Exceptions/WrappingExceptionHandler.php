<?php

declare(strict_types=1);

namespace AppWatch\Agent\Tests\Fixtures\Exceptions;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

/**
 * Wraps the app's exception handler without extending it, like Collision's console handler.
 */
final readonly class WrappingExceptionHandler implements ExceptionHandler
{
    /**
     * The handler every call is passed on to.
     */
    public function __construct(
        private ExceptionHandler $handler,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function report(Throwable $e): void
    {
        $this->handler->report($e);
    }

    /**
     * {@inheritDoc}
     */
    public function shouldReport(Throwable $e): bool
    {
        return $this->handler->shouldReport($e);
    }

    /**
     * {@inheritDoc}
     */
    public function render($request, Throwable $e)
    {
        return $this->handler->render($request, $e);
    }

    /**
     * {@inheritDoc}
     */
    public function renderForConsole($output, Throwable $e): void
    {
        $this->handler->renderForConsole($output, $e);
    }
}
