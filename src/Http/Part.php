<?php

declare(strict_types=1);

namespace Folk\Sdk\Http;

/**
 * One part of a streamed `multipart/form-data` request.
 *
 * Obtained from {@see \Folk\Sdk\Folk::nextPart()}. Parts are processed
 * sequentially: read the current part fully (or skip it) before calling
 * `nextPart()` again. The read methods pull from Folk's current-part cursor —
 * do not hold or read a Part after advancing to the next one.
 */
final class Part
{
    public function __construct(
        /** Form field name, or null if absent. */
        public readonly ?string $name,
        /** File name for file parts; null for plain fields. */
        public readonly ?string $filename,
        /** The part's declared Content-Type, if any. */
        public readonly ?string $contentType,
    ) {}

    /**
     * Whether this part is a file upload (has a filename).
     */
    public function isFile(): bool
    {
        return $this->filename !== null;
    }

    /**
     * Read up to $length bytes of this part's body, blocking until data is
     * available. Returns "" at the end of the part. A single call may return
     * fewer than $length bytes — loop until "".
     */
    public function read(int $length = 8192): string
    {
        return \function_exists('folk_part_read') ? \folk_part_read($length) : '';
    }

    /**
     * Read this part's entire body, blocking until its end.
     */
    public function readAll(): string
    {
        return \function_exists('folk_part_read_all') ? \folk_part_read_all() : '';
    }
}
