<?php

declare(strict_types=1);

namespace Folk\Sdk;

/**
 * Dependency-free UUID generation, used by the framework queue adapters to
 * stamp job/message ids consistently with Folk's request_id (UUID v7).
 */
final class Uuid
{
    /**
     * Generate a RFC 9562 version 7 (time-ordered) UUID.
     *
     * @return non-empty-string
     */
    public static function v7(): string
    {
        $unixTsMs = (int) (\microtime(true) * 1000);

        $bytes = \random_bytes(16);
        $bytes[0] = \chr(($unixTsMs >> 40) & 0xff);
        $bytes[1] = \chr(($unixTsMs >> 32) & 0xff);
        $bytes[2] = \chr(($unixTsMs >> 24) & 0xff);
        $bytes[3] = \chr(($unixTsMs >> 16) & 0xff);
        $bytes[4] = \chr(($unixTsMs >> 8) & 0xff);
        $bytes[5] = \chr($unixTsMs & 0xff);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0f) | 0x70); // version 7
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3f) | 0x80); // variant 10

        return self::format($bytes);
    }

    /**
     * Generate a RFC 4122 version 4 (random) UUID.
     *
     * @return non-empty-string
     */
    public static function v4(): string
    {
        $bytes = \random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3f) | 0x80); // variant 10

        return self::format($bytes);
    }

    /**
     * @return non-empty-string
     */
    private static function format(string $bytes): string
    {
        $hex = \bin2hex($bytes);

        return \sprintf(
            '%s-%s-%s-%s-%s',
            \substr($hex, 0, 8),
            \substr($hex, 8, 4),
            \substr($hex, 12, 4),
            \substr($hex, 16, 4),
            \substr($hex, 20, 12),
        );
    }
}
