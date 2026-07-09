<?php

declare(strict_types=1);

namespace Folk\Sdk\Tests;

use Folk\Sdk\Http\CookieParser;
use PHPUnit\Framework\TestCase;

final class CookieParserTest extends TestCase
{
    public function testParsesMultipleCookies(): void
    {
        $cookies = CookieParser::parse('XSRF-TOKEN=abc; laravel_session=xyz');
        self::assertSame(['XSRF-TOKEN' => 'abc', 'laravel_session' => 'xyz'], $cookies);
    }

    public function testUrlDecodesValues(): void
    {
        $cookies = CookieParser::parse('sess=a%3D%3Db%2Bc');
        self::assertSame('a==b+c', $cookies['sess']);
    }

    public function testEmptyHeaderYieldsEmptyArray(): void
    {
        self::assertSame([], CookieParser::parse(''));
    }

    public function testSkipsMalformedAndNamelessSegments(): void
    {
        $cookies = CookieParser::parse('=novalue; ; broken; ok=1');
        self::assertSame(['ok' => '1'], $cookies);
    }

    public function testFromHeadersIsCaseInsensitive(): void
    {
        self::assertSame(
            ['a' => '1'],
            CookieParser::fromHeaders(['Content-Type' => 'text/html', 'CooKie' => 'a=1']),
        );
    }

    public function testFromHeadersWithoutCookieHeader(): void
    {
        self::assertSame([], CookieParser::fromHeaders(['accept' => '*/*']));
    }
}
