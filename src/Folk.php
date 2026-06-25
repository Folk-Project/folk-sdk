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
     * A globally-unique UUID (v7), stable for the duration of a single request —
     * use it to correlate PHP application logs with Folk's Rust-side access logs.
     * Returns an empty string when no request is in flight or the Folk extension
     * is not loaded.
     */
    public static function requestId(): string
    {
        return \function_exists('folk_request_id') ? \folk_request_id() : '';
    }

    /**
     * Start a streaming HTTP response by sending the status code and headers.
     *
     * Must be called once per request before write() or end(). After calling
     * this, the return value of the PHP handler is ignored by Folk.
     *
     * @param int                  $status  HTTP status code (e.g. 200).
     * @param array<string,string> $headers Response headers.
     */
    public static function writeHead(int $status, array $headers = []): void
    {
        if (\function_exists('folk_write_head')) {
            \folk_write_head($status, \json_encode($headers, \JSON_THROW_ON_ERROR));
        }
    }

    /**
     * Send a chunk of the response body.
     *
     * writeHead() must have been called before this method. May be called
     * multiple times to stream the body incrementally.
     *
     * Blocks until the chunk is accepted (backpressure from a slow client will
     * block the PHP worker until space is available in the buffer).
     */
    public static function write(string $data): void
    {
        if (\function_exists('folk_write')) {
            \folk_write($data);
        }
    }

    /**
     * Finish the streaming response.
     *
     * No more writes are possible after calling this method. The PHP handler
     * should return immediately after calling end().
     */
    public static function end(): void
    {
        if (\function_exists('folk_write_end')) {
            \folk_write_end();
        }
    }

    /**
     * Read up to $length bytes of the streaming request body.
     *
     * Returns the next chunk of the request body, or an empty string at
     * end-of-body. Blocks until data is available. Only yields data when the
     * HTTP plugin runs in streaming mode (`stream_request_body = true`);
     * otherwise the body is in $payload['body'] and this returns "".
     *
     * A single call may return fewer than $length bytes — loop until "".
     */
    public static function read(int $length = 8192): string
    {
        return \function_exists('folk_read') ? \folk_read($length) : '';
    }

    /**
     * Read the entire streaming request body, blocking until end-of-body.
     *
     * Returns "" when there is no streaming body (buffered mode or no body).
     */
    public static function readAll(): string
    {
        return \function_exists('folk_read_all') ? \folk_read_all() : '';
    }
}
