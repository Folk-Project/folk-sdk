<?php declare(strict_types=1);

use Folk\Sdk\Grpc\Context;
use Folk\Sdk\Grpc\GrpcRequest;
use Folk\Sdk\Grpc\GrpcRouter;
use Folk\Sdk\Tests\GrpcFixtures\Color;
use Folk\Sdk\Tests\GrpcFixtures\Everything;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixtures/dto.php';

/**
 * Client-streaming service (phase 94, #92): a stream of requests, one response.
 * `INPUT_STREAMS` tells the router which DTO to hydrate each inbound message into
 * (the `iterable` param carries no element type at runtime).
 */
interface InboundCollectSvc
{
    public const INPUT_STREAMS = ['Collect' => Everything::class];

    /** @param iterable<Everything> $requests */
    public function Collect(iterable $requests, Context $context): ?Everything;
}

/** Bidi service (phase 94, #92): a stream of requests, a stream of responses. */
interface InboundChatSvc
{
    public const INPUT_STREAMS = ['Chat' => Everything::class];

    /**
     * @param  iterable<Everything> $requests
     * @return iterable<Everything>
     */
    public function Chat(iterable $requests, Context $context): iterable;
}

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
                return new Everything(name: $request->getName() . '!', color: $request->getColor());
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
                yield new Everything(name: $request->getName() . '-0');
                yield new Everything(name: $request->getName() . '-1');
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

    /**
     * Client-streaming (phase 94, #92): the router hydrates each inbound message
     * into the method's request DTO (resolved from INPUT_STREAMS) and passes an
     * `iterable $requests`; the single response DTO is framed under `__message`.
     */
    public function testClientStreamingHydratesInboundAndReturnsSingle(): void
    {
        $handler = new class implements InboundCollectSvc {
            public function Collect(iterable $requests, Context $context): ?Everything
            {
                $names = [];
                foreach ($requests as $req) {
                    // $req is typed Everything via `@param iterable<Everything>` —
                    // property access type-checks at phpstan L8 (proves autocomplete).
                    $names[] = $req->getName();
                }
                return new Everything(name: implode(',', $names));
            }
        };
        $router = new GrpcRouter();
        $router->register('test.Svc', $handler);

        $result = $router->call(GrpcRequest::fromPayload(
            [
                'service' => 'test.Svc',
                'method' => 'Collect',
                'encoding' => 'json',
                'client_streaming' => true,
                'metadata' => [],
            ],
            [['name' => 'a'], ['name' => 'b'], ['name' => 'c']],
        ));

        $this->assertIsArray($result);
        $this->assertArrayHasKey('__message', $result);
        $this->assertSame('a,b,c', $result['__message']['name']);
    }

    /**
     * Bidi (phase 94, #92): the handler returns a generator of response DTOs; the
     * router dehydrates each for the WorkerLoop to frame one gRPC message at a time.
     */
    public function testBidiEchoesHydratedInboundStream(): void
    {
        $handler = new class implements InboundChatSvc {
            public function Chat(iterable $requests, Context $context): iterable
            {
                foreach ($requests as $req) {
                    yield new Everything(name: 'echo:' . $req->getName());
                }
            }
        };
        $router = new GrpcRouter();
        $router->register('test.Svc', $handler);

        $result = $router->call(GrpcRequest::fromPayload(
            [
                'service' => 'test.Svc',
                'method' => 'Chat',
                'encoding' => 'json',
                'client_streaming' => true,
                'metadata' => [],
            ],
            [['name' => 'x'], ['name' => 'y']],
        ));

        $this->assertInstanceOf(\Generator::class, $result);
        /** @var \Generator<int, array<string, mixed>> $result */
        $messages = iterator_to_array($result, false);
        $this->assertCount(2, $messages);
        $this->assertSame('echo:x', $messages[0]['name']);
        $this->assertSame('echo:y', $messages[1]['name']);
    }

    /**
     * Client-streaming business status: a status set while draining the inbound
     * stream suppresses the response body (null), as on the unary tier.
     */
    public function testClientStreamingBusinessStatusReturnsNull(): void
    {
        $handler = new class implements InboundCollectSvc {
            public function Collect(iterable $requests, Context $context): ?Everything
            {
                foreach ($requests as $req) {
                    unset($req); // drain the inbound stream
                }
                $context->setStatus(7, 'denied');
                return null;
            }
        };
        $router = new GrpcRouter();
        $router->register('test.Svc', $handler);

        $result = $router->call(GrpcRequest::fromPayload(
            [
                'service' => 'test.Svc',
                'method' => 'Collect',
                'encoding' => 'json',
                'client_streaming' => true,
                'metadata' => [],
            ],
            [['name' => 'a']],
        ));

        $this->assertNull($result);
    }
}
