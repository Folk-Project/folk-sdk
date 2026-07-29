<?php

declare(strict_types=1);

namespace {
    // Fake the native extension function so Folk::grpcYield() forwards to it and
    // the test can capture the framed envelopes. In a real worker this is
    // provided by the Folk PHP extension (phase 88b §4b). Declared once, guarded.
    if (!\function_exists('folk_grpc_yield')) {
        function folk_grpc_yield(string $message_json): void
        {
            \Folk\Sdk\Tests\Worker\GrpcYieldCapture::$yields[] = $message_json;
        }
    }
}

namespace Folk\Sdk\Tests\Worker {

use Folk\Sdk\Grpc\GrpcModeHandler;
use Folk\Sdk\Grpc\GrpcRequest;
use Folk\Sdk\Reset\ResettableInterface;
use Folk\Sdk\Worker\WorkerLoop;
use PHPUnit\Framework\TestCase;

/** Typed sink for the faked `folk_grpc_yield` (avoids untyped $GLOBALS). */
final class GrpcYieldCapture
{
    /** @var list<string> */
    public static array $yields = [];
}

/**
 * Phase 88b §4c — WorkerLoop drains a server-streaming (generator) gRPC handler,
 * framing each yielded DTO as a `{__message: dto}` and a trailing business
 * status as `{__grpc_status, __grpc_message}`.
 */
final class WorkerLoopGrpcStreamTest extends TestCase
{
    protected function setUp(): void
    {
        GrpcYieldCapture::$yields = [];
    }

    /** @return list<array<string, mixed>> */
    private function capturedYields(): array
    {
        $out = [];
        foreach (GrpcYieldCapture::$yields as $json) {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $out[] = $decoded;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function grpcParams(): array
    {
        return [
            'service'  => 'folk.test.stream.Watcher',
            'method'   => 'Watch',
            'encoding' => 'json',
            'message'  => ['topic' => 'prices'],
        ];
    }

    public function testServerStreamingYieldsEachMessageWrappedInEnvelope(): void
    {
        $handler = new class implements GrpcModeHandler {
            public function call(GrpcRequest $request): \Generator
            {
                return (function (): \Generator {
                    yield ['seq' => 0, 'value' => 'a'];
                    yield ['seq' => 1, 'value' => 'b'];
                    yield ['seq' => 2, 'value' => 'c'];
                })();
            }
        };

        $loop = new WorkerLoop();
        $loop->registerGrpcHandler($handler);

        $result = $loop->dispatchDirect('grpc.call', $this->grpcParams());

        // The handler streamed via yields; the dispatch return value is inert.
        self::assertSame(['__result' => null], $result);

        self::assertSame(
            [
                ['__message' => ['seq' => 0, 'value' => 'a']],
                ['__message' => ['seq' => 1, 'value' => 'b']],
                ['__message' => ['seq' => 2, 'value' => 'c']],
            ],
            $this->capturedYields(),
            'each yielded DTO is framed as a {__message} envelope, in order',
        );
    }

    public function testTrailingBusinessStatusYieldedAfterMessages(): void
    {
        $handler = new class implements GrpcModeHandler {
            public function call(GrpcRequest $request): \Generator
            {
                $ctx = $request->context;

                return (function () use ($ctx): \Generator {
                    yield ['seq' => 0, 'value' => 'a'];
                    // Business status set at the end of the stream (NOT_FOUND=5).
                    $ctx->setStatus(5, 'gone');
                })();
            }
        };

        $loop = new WorkerLoop();
        $loop->registerGrpcHandler($handler);

        $loop->dispatchDirect('grpc.call', $this->grpcParams());

        self::assertSame(
            [
                ['__message' => ['seq' => 0, 'value' => 'a']],
                ['__grpc_status' => 5, '__grpc_message' => 'gone'],
            ],
            $this->capturedYields(),
            'the message is yielded first, then the trailing business status',
        );
    }

    public function testEmptyGeneratorYieldsNothing(): void
    {
        $handler = new class implements GrpcModeHandler {
            public function call(GrpcRequest $request): \Generator
            {
                return (function (): \Generator {
                    yield from []; // an empty stream
                })();
            }
        };

        $loop = new WorkerLoop();
        $loop->registerGrpcHandler($handler);

        $result = $loop->dispatchDirect('grpc.call', $this->grpcParams());

        self::assertSame(['__result' => null], $result);
        self::assertSame([], $this->capturedYields(), 'an empty stream yields no envelopes');
    }

    public function testResettersRunAfterStreamDrains(): void
    {
        // Per-request state reset (#86) must run once the whole stream is drained,
        // on the normal path — the generator is consumed inside dispatchDirect's
        // try, so the finally runResetters() fires after the last yield.
        $handler = new class implements GrpcModeHandler {
            public function call(GrpcRequest $request): \Generator
            {
                return (function (): \Generator {
                    yield ['seq' => 0, 'value' => 'a'];
                    yield ['seq' => 1, 'value' => 'b'];
                })();
            }
        };
        $counter = new class implements ResettableInterface {
            public int $count = 0;
            public function reset(): void
            {
                $this->count++;
            }
        };

        $loop = new WorkerLoop();
        $loop->registerResetter($counter);
        $loop->registerGrpcHandler($handler);

        $loop->dispatchDirect('grpc.call', $this->grpcParams());

        self::assertCount(2, $this->capturedYields(), 'both messages streamed');
        self::assertSame(1, $counter->count, 'resetter runs exactly once, after the stream drains');
    }

    public function testUnaryHandlerStillReturnsMessageEnvelope(): void
    {
        // Negative control: a non-Traversable (unary) response is unchanged.
        $handler = new class implements GrpcModeHandler {
            /** @return array<string, mixed> */
            public function call(GrpcRequest $request): array
            {
                return ['__message' => ['message' => 'hi']];
            }
        };

        $loop = new WorkerLoop();
        $loop->registerGrpcHandler($handler);

        $result = $loop->dispatchDirect('grpc.call', $this->grpcParams());

        self::assertSame(['__message' => ['message' => 'hi']], $result);
        self::assertSame([], $this->capturedYields(), 'unary does not stream');
    }
}

}
