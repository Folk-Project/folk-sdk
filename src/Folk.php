<?php

declare(strict_types=1);

namespace Folk\Sdk;

/**
 * Folk runtime facade.
 *
 * Thin wrapper around native functions exposed by the Folk PHP extension.
 * Safe to call without the extension loaded — methods degrade gracefully.
 */
final class Folk
{
    /**
     * Id of the request currently being handled.
     *
     * Stable for the duration of a single request — use it to correlate PHP
     * application logs with Folk's Rust-side access logs. Returns 0 when no
     * request is in flight or the Folk extension is not loaded.
     */
    public static function requestId(): int
    {
        return \function_exists('folk_request_id') ? \folk_request_id() : 0;
    }
}
