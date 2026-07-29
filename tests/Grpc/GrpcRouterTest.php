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

    /**
     * Server-streaming (phase 88b, #32): a handler returning `iterable<Dto>` is
     * routed to a generator of dehydrated message arrays for the WorkerLoop to
     * frame one at a time.
     */
    public function testServerStreamingReturnsGeneratorOfDehydratedMessages(): void
    {
        $handler = new class {
            /** @return \Generator<int, Everything> */
            public function Watch(Everything $request, Context $context): \Generator
            {
                yield new Everything(name: $request->name . '-0');
                yield new Everything(name: $request->name . '-1');
            }
        };

        $router = new GrpcRouter();
        $router->register('test.Svc', $handler);

        $result = $router->call(GrpcRequest::fromPayload([
            'service' => 'test.Svc',
            'method' => 'Watch',
            'encoding' => 'json',
            'message' => ['name' => 'ada'],
            'metadata' => [],
        ]));

        $this->assertInstanceOf(\Traversable::class, $result);
        $messages = iterator_to_array($result);
        $this->assertCount(2, $messages);
        $this->assertSame('ada-0', $messages[0]['name']);
        $this->assertSame('ada-1', $messages[1]['name']);
    }

    /**
     * A business status set DURING a server-stream must not be swallowed: the
     * router returns the generator (not null) and the status is observable after
     * draining — the WorkerLoop reads it to end the stream with that gRPC code.
     */
    public function testServerStreamingStatusReadableAfterDrain(): void
    {
        $handler = new class {
            /** @return \Generator<int, Everything> */
            public function Watch(Everything $request, Context $context): \Generator
            {
                yield new Everything(name: 'first');
                $context->setStatus(5, 'gone');
            }
        };

        $router = new GrpcRouter();
        $router->register('test.Svc', $handler);

        $request = GrpcRequest::fromPayload([
            'service' => 'test.Svc',
            'method' => 'Watch',
            'encoding' => 'json',
            'message' => ['name' => 'x'],
            'metadata' => [],
        ]);
        $result = $router->call($request);

        $this->assertInstanceOf(\Traversable::class, $result);
        $messages = iterator_to_array($result);
        $this->assertCount(1, $messages, 'the message before the status was produced');
        $this->assertSame(['code' => 5, 'message' => 'gone'], $request->context->getStatus());
    }

    public function testServerStreamingRejectsNonObjectYield(): void
    {
        $handler = new class {
            /** @return \Generator<int, mixed> */
            public function Watch(Everything $request, Context $context): \Generator
            {
                yield ['not', 'a', 'dto'];
            }
        };

        $router = new GrpcRouter();
        $router->register('test.Svc', $handler);

        $result = $router->call(GrpcRequest::fromPayload([
            'service' => 'test.Svc',
            'method' => 'Watch',
            'encoding' => 'json',
            'message' => ['name' => 'x'],
            'metadata' => [],
        ]));

        $this->assertInstanceOf(\Traversable::class, $result);
        $this->expectException(\RuntimeException::class);
        iterator_to_array($result); // draining triggers the per-item type check
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
