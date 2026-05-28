<?php

declare(strict_types=1);

namespace Folk\Sdk\Reset;

/**
 * Reset framework state after each request.
 * Called by the worker loop between requests.
 */
interface ResettableInterface
{
    public function reset(): void;
}
