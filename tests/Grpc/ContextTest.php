<?php declare(strict_types=1);

use Folk\Sdk\Grpc\Context;
use PHPUnit\Framework\TestCase;

final class ContextTest extends TestCase
{
    public function testMetadataMultimapFirstAndAll(): void
    {
        $ctx = new Context(['X-Trace' => ['a', 'b'], 'single' => 'one']);

        $this->assertTrue($ctx->has('x-trace'), 'lookup is case-insensitive');
        $this->assertSame('a', $ctx->getValue('X-Trace'), 'getValue returns the first value');
        $this->assertSame(['a', 'b'], $ctx->getAll('x-trace'), 'getAll returns the full multimap');
        $this->assertSame(['one'], $ctx->getAll('single'), 'scalar normalised to a one-element list');
        $this->assertSame([], $ctx->getAll('absent'));
        $this->assertNull($ctx->getValue('absent'));
    }

    public function testGetMetadataViews(): void
    {
        $ctx = new Context(['k' => ['v1', 'v2']]);
        $this->assertSame(['k' => 'v1'], $ctx->getMetadata(), 'first-value view');
        $this->assertSame(['k' => ['v1', 'v2']], $ctx->getMetadataMulti(), 'full multimap');
    }

    public function testAuthorizationAndBearerToken(): void
    {
        $ctx = new Context(['authorization' => 'Bearer abc.def']);
        $this->assertSame('Bearer abc.def', $ctx->authorization());
        $this->assertSame('abc.def', $ctx->bearerToken());

        $plain = new Context(['authorization' => 'Basic xyz']);
        $this->assertNull($plain->bearerToken(), 'non-bearer scheme yields no token');

        $none = new Context();
        $this->assertNull($none->authorization());
        $this->assertNull($none->bearerToken());
    }

    public function testGetBinaryDecodesBase64(): void
    {
        $ctx = new Context(['token-bin' => base64_encode("\x01\x02")]);
        $this->assertSame("\x01\x02", $ctx->getBinary('token-bin'));

        $bad = new Context(['token-bin' => '!!!not-base64!!!']);
        $this->assertNull($bad->getBinary('token-bin'), 'invalid base64 → null');
        $this->assertNull($ctx->getBinary('absent'));
    }

    public function testCallInfoFields(): void
    {
        $ctx = new Context([], 'pkg.Service', 'DoThing', 'req-123', null, '10.0.0.1:5000', 'example.test');
        $this->assertSame('pkg.Service', $ctx->service());
        $this->assertSame('DoThing', $ctx->method());
        $this->assertSame('req-123', $ctx->requestId());
        $this->assertSame('10.0.0.1:5000', $ctx->peerAddress());
        $this->assertSame('example.test', $ctx->authority());
    }

    public function testRequestIdDefaultsToEmpty(): void
    {
        $this->assertSame('', (new Context())->requestId());
    }

    public function testDeadline(): void
    {
        $none = new Context();
        $this->assertFalse($none->hasDeadline());
        $this->assertNull($none->timeoutSeconds());
        $this->assertNull($none->remaining());

        $ctx = new Context([], '', '', null, 30.0);
        $this->assertTrue($ctx->hasDeadline());
        $this->assertSame(30.0, $ctx->timeoutSeconds());
        $remaining = $ctx->remaining();
        $this->assertNotNull($remaining);
        $this->assertGreaterThan(0.0, $remaining);
        $this->assertLessThanOrEqual(30.0, $remaining);
    }
}
