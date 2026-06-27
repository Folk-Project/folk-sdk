<?php

declare(strict_types=1);

namespace Folk\Sdk\Tests;

use Folk\Sdk\Uuid;
use PHPUnit\Framework\TestCase;

final class UuidTest extends TestCase
{
    public function testV7HasVersionAndVariantBits(): void
    {
        $uuid = Uuid::v7();

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid,
        );
    }

    public function testV4HasVersionAndVariantBits(): void
    {
        $uuid = Uuid::v4();

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid,
        );
    }

    public function testV7IsTimeOrdered(): void
    {
        $first = Uuid::v7();
        \usleep(2000);
        $second = Uuid::v7();

        // Time-ordered: lexicographic comparison follows creation order.
        self::assertLessThan(0, \strcmp($first, $second));
    }

    public function testValuesAreUnique(): void
    {
        $ids = [];
        for ($i = 0; $i < 100; $i++) {
            $ids[Uuid::v7()] = true;
        }

        self::assertCount(100, $ids);
    }
}
