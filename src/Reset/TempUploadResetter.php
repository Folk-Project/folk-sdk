<?php

declare(strict_types=1);

namespace Folk\Sdk\Reset;

use Folk\Sdk\Http\StreamedBody;

/**
 * Safety-net resetter that deletes any spooled upload temp files left behind by
 * a request whose handler died before its own `finally` cleanup ran.
 *
 * The primary cleanup is the `finally` block in the HTTP handler; this runs
 * between requests via {@see \Folk\Sdk\Worker\WorkerLoop::runResetters()} and
 * only ever finds files when that primary path was skipped (fatal error).
 */
final class TempUploadResetter implements ResettableInterface
{
    public function reset(): void
    {
        StreamedBody::purgeRegistry();
    }
}
