<?php declare(strict_types=1);

use Folk\Sdk\Grpc\Context;
use Folk\Sdk\Grpc\GrpcRequest;
use Folk\Sdk\Grpc\GrpcRouter;
use Folk\Sdk\Tests\GrpcFixtures\Color;
use Folk\Sdk\Tests\GrpcFixtures\Everything;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixtures/dto.php';

final class GrpcRouterTest extends TestCase
{
    /**
     * Transcode tier: the plugin sends a decoded `message`; the router hydrates it
     * into the handler's DTO, calls it, and returns the DTO result flattened under
     * `__message` for re-encoding.
     */
    public function testTranscodeTierHydratesAndDehydrates(): void
    {
        $handler = new class {
            public function Echo(Everything $request, Context $context): Everything
            {
                return new Everything(name: $request->name . '!', color: $request->color);
            }
        };

        $router = new GrpcRouter();
        $router->register('test.Svc', $handler);

        $result = $router->call(GrpcRequest::fromPayload([
            'service' => 'test.Svc',
            'method' => 'Echo',
            'encoding' => 'json',
            'message' => ['name' => 'ada', 'color' => Color::RED->value],
            'metadata' => [],
        ]));

        $this->assertIsArray($result);
        $this->assertArrayHasKey('__message', $result);
        $this->assertSame('ada!', $result['__message']['name']);
        $this->assertSame(Color::RED->value, $result['__message']['color']);
    }

    /** A business status on the transcode tier suppresses the body (null). */
    public function testTranscodeTierBusinessStatusReturnsNull(): void
    {
        $handler = new class {
            public function Echo(Everything $request, Context $context): ?Everything
            {
                $context->setStatus(5, 'not found');
                return null;
            }
        };

        $router = new GrpcRouter();
        $router->register('test.Svc', $handler);

        $result = $router->call(GrpcRequest::fromPayload([
            'service' => 'test.Svc',
            'method' => 'Echo',
            'encoding' => 'json',
            'message' => ['name' => 'x'],
            'metadata' => [],
        ]));

        $this->assertNull($result);
    }

    /** Passthrough tier (transcode off): a raw-string handler returns raw bytes. */
    public function testPassthroughTierRawString(): void
    {
        $handler = new class {
            public function M(string $payload): string
            {
                return 'resp:' . $payload;
            }
        };

        $router = new GrpcRouter();
        $router->register('p.Svc', $handler);

        $result = $router->call(GrpcRequest::fromPayload([
            'service' => 'p.Svc',
            'method' => 'M',
            'payload' => base64_encode('req'),
            'metadata' => [],
        ]));

        $this->assertSame('resp:req', $result);
    }

    public function testUnknownServiceThrows(): void
    {
        $router = new GrpcRouter();
        $this->expectException(\RuntimeException::class);
        $router->call(GrpcRequest::fromPayload(['service' => 'nope', 'method' => 'X', 'metadata' => []]));
    }

    public function testUnknownMethodThrows(): void
    {
        $handler = new class {
            public function Known(string $p): string
            {
                return $p;
            }
        };
        $router = new GrpcRouter();
        $router->register('s.Svc', $handler);

        $this->expectException(\RuntimeException::class);
        $router->call(GrpcRequest::fromPayload(['service' => 's.Svc', 'method' => 'Missing', 'metadata' => []]));
    }
}
