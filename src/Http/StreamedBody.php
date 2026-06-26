<?php

declare(strict_types=1);

namespace Folk\Sdk\Http;

use Folk\Sdk\Folk;

/**
 * Consumes a streamed request body (Phase 70/71 primitives) and re-materialises
 * it into the shape a framework expects.
 *
 * Two modes:
 *  - {@see drainMultipart()} — pulls `Folk::nextPart()`, spooling file parts to
 *    temp files (chunked, so only one 64 KiB chunk lives in memory at a time)
 *    and collecting text parts into {@see $post}. Adapters turn {@see $files}
 *    into native uploaded-file objects.
 *  - {@see readRaw()} — concatenates a non-multipart streamed body into a string.
 *
 * Folk is a long-running worker: the SAPI does not clean up our temp files.
 * Callers MUST invoke {@see cleanup()} in a `finally` block. As a safety net for
 * fatal paths, every spooled file is also tracked in a process-wide registry
 * that {@see \Folk\Sdk\Reset\TempUploadResetter} drains between requests.
 *
 * A `maxBytes` limit (0 = unlimited) caps the total streamed size; exceeding it
 * throws {@see StreamLimitExceededException} (adapters map it to HTTP 413).
 */
final class StreamedBody
{
    private const CHUNK = 65536;

    /** @var list<SpooledFile> */
    public array $files = [];

    /** @var array<string, string> */
    public array $post = [];

    /** @var list<string> Temp paths created by this instance. */
    private array $spooled = [];

    /** @var array<string, true> Process-wide registry of live temp paths. */
    private static array $registry = [];

    public function __construct(private readonly int $maxBytes = 0) {}

    /**
     * Resolve the streamed-body byte limit for a request URI: the first matching
     * per-path limit (a pattern ending in `*` is a prefix match, otherwise
     * exact), else the default.
     *
     * @param array<string, int> $pathLimits
     */
    public static function resolveLimit(string $uri, int $default, array $pathLimits): int
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path)) {
            $path = $uri;
        }
        foreach ($pathLimits as $pattern => $limit) {
            if (str_ends_with($pattern, '*')) {
                if (str_starts_with($path, rtrim($pattern, '*'))) {
                    return (int) $limit;
                }
            } elseif ($path === $pattern) {
                return (int) $limit;
            }
        }
        return $default;
    }

    /**
     * Drain a streamed `multipart/form-data` body: file parts → temp files
     * ({@see $files}), text parts → {@see $post}.
     */
    public function drainMultipart(): void
    {
        $total = 0;
        while (($part = Folk::nextPart()) !== null) {
            if ($part->isFile()) {
                $total = $this->spoolFile($part, $total);
            } else {
                $value = '';
                while (($chunk = $part->read(self::CHUNK)) !== '') {
                    $total += \strlen($chunk);
                    $this->guard($total);
                    $value .= $chunk;
                }
                if ($part->name !== null) {
                    $this->post[$part->name] = $value;
                }
            }
        }
    }

    /**
     * Read a non-multipart streamed body into a string, enforcing the limit.
     */
    public function readRaw(): string
    {
        $body = '';
        $total = 0;
        while (($chunk = Folk::read(self::CHUNK)) !== '') {
            $total += \strlen($chunk);
            $this->guard($total);
            $body .= $chunk;
        }
        return $body;
    }

    /**
     * Delete every temp file spooled by this instance. Idempotent; safe to call
     * from `finally` whether or not draining succeeded.
     */
    public function cleanup(): void
    {
        foreach ($this->spooled as $path) {
            @unlink($path);
            unset(self::$registry[$path]);
        }
        $this->spooled = [];
    }

    /**
     * Safety-net cleanup of any temp files left registered by a worker that
     * died before {@see cleanup()} ran. Called by {@see TempUploadResetter}.
     */
    public static function purgeRegistry(): void
    {
        foreach (array_keys(self::$registry) as $path) {
            @unlink($path);
        }
        self::$registry = [];
    }

    private function spoolFile(Part $part, int $total): int
    {
        $tmp = tempnam(sys_get_temp_dir(), 'folk_up_');
        if ($tmp === false) {
            throw new \RuntimeException('failed to create temp file for upload');
        }
        $this->spooled[] = $tmp;
        self::$registry[$tmp] = true;

        $handle = fopen($tmp, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("failed to open temp file: {$tmp}");
        }

        $size = 0;
        try {
            while (($chunk = $part->read(self::CHUNK)) !== '') {
                $len = \strlen($chunk);
                $total += $len;
                $size += $len;
                $this->guard($total);
                fwrite($handle, $chunk);
            }
        } finally {
            fclose($handle);
        }

        $this->files[] = new SpooledFile(
            field: $part->name,
            tmpPath: $tmp,
            originalName: $part->filename,
            contentType: $part->contentType,
            size: $size,
        );

        return $total;
    }

    private function guard(int $total): void
    {
        if ($this->maxBytes > 0 && $total > $this->maxBytes) {
            throw new StreamLimitExceededException(
                "streamed request body exceeds limit of {$this->maxBytes} bytes",
            );
        }
    }
}
