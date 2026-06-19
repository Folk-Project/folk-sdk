<?php declare(strict_types=1);

use Folk\Sdk\Folk;
use PHPUnit\Framework\TestCase;

final class FolkTest extends TestCase
{
    public function testRequestIdFallsBackToEmptyStringWithoutExtension(): void
    {
        // The Folk extension is not loaded during unit tests, so folk_request_id()
        // is undefined and the facade must return '' rather than error.
        $this->assertFalse(\function_exists('folk_request_id'));
        $this->assertSame('', Folk::requestId());
    }
}
