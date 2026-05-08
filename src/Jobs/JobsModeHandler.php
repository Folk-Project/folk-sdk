<?php

declare(strict_types=1);

namespace Folk\Sdk\Jobs;

interface JobsModeHandler
{
    public function process(mixed $payload): mixed;
}
