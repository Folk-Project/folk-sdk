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

    /**
     * Advance to the next part of a streamed multipart/form-data request.
     *
     * Returns the next {@see Http\Part}, or null when there are no more parts
     * (or the request is not a multipart streaming request, or the extension is
     * not loaded). Any unread data of the current part is drained first, so it
     * is safe to skip parts. Read a file part's body with `$part->read()`.
     *
     * Yields parts only when the HTTP plugin runs with stream_request_body = true
     * and the request is `multipart/form-data`.
     */
    public static function nextPart(): ?Http\Part
    {
        if (!\function_exists('folk_next_part')) {
            return null;
        }
        $json = \folk_next_part();
        if ($json === null) {
            return null;
        }
        /** @var array{name?:?string, filename?:?string, content_type?:?string} $meta */
        $meta = \json_decode($json, true, 16, \JSON_THROW_ON_ERROR);

        return new Http\Part(
            name: $meta['name'] ?? null,
            filename: $meta['filename'] ?? null,
            contentType: $meta['content_type'] ?? null,
        );
    }
}
