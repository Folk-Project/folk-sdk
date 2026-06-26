<?php

declare(strict_types=1);

namespace Folk\Sdk\Http;

/**
 * A streamed multipart file part spooled to a temporary file on disk.
 *
 * Produced by {@see StreamedBody::drainMultipart()}. Framework adapters wrap
 * `$tmpPath` in their native uploaded-file abstraction (Laravel/Symfony
 * `UploadedFile`, PSR-7 `UploadedFileInterface`). The temp file is owned by the
 * {@see StreamedBody} that created it and is deleted on `StreamedBody::cleanup()`.
 */
final readonly class SpooledFile
{
    public function __construct(
        /** Form field name (the part's `name`), or null if absent. */
        public ?string $field,
        /** Absolute path to the spooled temp file on disk. */
        public string $tmpPath,
        /** Client-provided file name, or null. */
        public ?string $originalName,
        /** Declared Content-Type of the part, or null. */
        public ?string $contentType,
        /** Number of bytes written to the temp file. */
        public int $size,
    ) {}
}
