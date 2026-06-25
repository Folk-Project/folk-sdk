<?php declare(strict_types=1);

use Folk\Sdk\Grpc\Context;
use Folk\Sdk\Grpc\GrpcModeHandler;
use Folk\Sdk\Worker\WorkerLoop;
use PHPUnit\Framework\TestCase;

final class GrpcStatusTest extends TestCase
{
    public function testContextStatusDefaultsToNull(): void
    {
        $ctx = new Context();
        $this->assertNull($ctx->getStatus());
    }

    public function testContextSetStatus(): void
    {
        $ctx = new Context();
        $ctx->setStatus(5, 'user not found');
        $this->assertSame(['code' => 5, 'message' => 'user not found'], $ctx->getStatus());
    }

    public function testGrpcHandlerWithStatusReturnsGrpcStatusEnvelope(): void
    {
        $handler = new class implements GrpcModeHandler {
            public function call(string $service, string $method, string $payload, Context $context): ?string
            {
                $context->setStatus(5, 'nope');
                return null;
            }
        };

        $loop = new WorkerLoop();
        $loop->registerGrpcHandler($handler);

        $result = $loop->dispatchDirect('grpc.call', [
            'service'  => 'svc',
            'method'   => 'M',
            'payload'  => base64_encode('req'),
            'metadata' => [],
        ]);

        $this->assertSame(['__grpc_status' => 5, '__grpc_message' => 'nope'], $result);
    }

    public function testGrpcHandlerWithoutStatusReturnsBase64Result(): void
    {
        $handler = new class implements GrpcModeHandler {
            public function call(string $service, string $method, string $payload, Context $context): ?string
            {
                return 'response-bytes';
            }
        };

        $loop = new WorkerLoop();
        $loop->registerGrpcHandler($handler);

        $result = $loop->dispatchDirect('grpc.call', [
            'service'  => 'svc',
            'method'   => 'M',
            'payload'  => base64_encode('req'),
            'metadata' => [],
        ]);

        $this->assertSame(['__result' => base64_encode('response-bytes')], $result);
    }

    public function testFatalErrorOmitsTraceWithoutDevMode(): void
    {
        putenv('FOLK_DEV_MODE');
        $loop = new WorkerLoop();
        $loop->register('boom', static function (): mixed {
            throw new \RuntimeException('kaboom');
        });

        $result = $loop->dispatchDirect('boom', []);

        $this->assertSame('kaboom', $result['__error']);
        $this->assertArrayNotHasKey('__error_class', $result);
        $this->assertArrayNotHasKey('__error_trace', $result);
    }

    public function testFatalErrorIncludesClassAndTraceInDevMode(): void
    {
        putenv('FOLK_DEV_MODE=1');
        try {
            $loop = new WorkerLoop();
            $loop->register('boom', static function (): mixed {
                throw new \RuntimeException('kaboom');
            });

            $result = $loop->dispatchDirect('boom', []);

            $this->assertSame('kaboom', $result['__error']);
            $this->assertSame(\RuntimeException::class, $result['__error_class']);
            $this->assertIsString($result['__error_trace']);
        } finally {
            putenv('FOLK_DEV_MODE');
        }
    }
}
