<?php

declare(strict_types=1);

use Folk\Sdk\Http\HttpModeHandler;
use Folk\Sdk\Http\HttpRequest;
use Folk\Sdk\Http\HttpResponse;
use Folk\Sdk\Jobs\JobsModeHandler;
use Folk\Sdk\Worker\EmbedLoop;
use Folk\Sdk\Worker\HandlerLoop;
use PHPUnit\Framework\TestCase;

final class EmbedLoopTest extends TestCase
{
    public function testImplementsHandlerLoop(): void
    {
        $loop = new EmbedLoop();
        $this->assertInstanceOf(HandlerLoop::class, $loop);
    }

    public function testRunRegistersGlobalFunction(): void
    {
        $loop = new EmbedLoop();
        $loop->run();

        $this->assertTrue(function_exists('folk_embed_dispatch'));
        $this->assertSame($loop, EmbedLoop::$instance);
    }

    public function testEchoHandler(): void
    {
        $loop = new EmbedLoop();
        $loop->run();

        $params = msgpack_pack(['hello' => 'world']);
        $response = $loop->dispatch('echo', $params);
        $result = msgpack_unpack($response);

        $this->assertSame(['hello' => 'world'], $result);
    }

    public function testCustomHandler(): void
    {
        $loop = new EmbedLoop();
        $loop->register('test.ping', fn(mixed $params) => 'pong:' . $params);
        $loop->run();

        $response = $loop->dispatch('test.ping', msgpack_pack('hello'));
        $this->assertSame('pong:hello', msgpack_unpack($response));
    }

    public function testHttpHandler(): void
    {
        $handler = new class implements HttpModeHandler {
            public function handle(HttpRequest $request): HttpResponse
            {
                return new HttpResponse(
                    status: 200,
                    headers: ['Content-Type' => 'text/plain'],
                    body: "Hello {$request->method} {$request->uri}",
                );
            }
        };

        $loop = new EmbedLoop();
        $loop->registerHttpHandler($handler);
        $loop->run();

        $params = msgpack_pack([
            'method' => 'GET',
            'uri' => '/test',
            'headers' => [],
            'body' => '',
        ]);

        $response = msgpack_unpack($loop->dispatch('http.handle', $params));

        $this->assertSame(200, $response['status']);
        $this->assertSame('Hello GET /test', $response['body']);
    }

    public function testJobsHandler(): void
    {
        $handler = new class implements JobsModeHandler {
            public function process(mixed $params): mixed
            {
                return ['processed' => true, 'input' => $params];
            }
        };

        $loop = new EmbedLoop();
        $loop->registerJobsHandler($handler);
        $loop->run();

        $response = msgpack_unpack($loop->dispatch('jobs.process', msgpack_pack(['job' => 'test'])));
        $this->assertTrue($response['processed']);
    }

    public function testDispatchRouting(): void
    {
        $handler = new class implements HttpModeHandler {
            public function handle(HttpRequest $request): HttpResponse
            {
                return new HttpResponse(body: 'routed');
            }
        };

        $loop = new EmbedLoop();
        $loop->registerHttpHandler($handler);
        $loop->run();

        // "dispatch" should route to http.handle
        $params = msgpack_pack([
            'method' => 'GET',
            'uri' => '/',
            'headers' => [],
            'body' => '',
        ]);
        $response = msgpack_unpack($loop->dispatch('dispatch', $params));
        $this->assertSame('routed', $response['body']);
    }

    public function testResettersCalled(): void
    {
        $counter = new class {
            public int $count = 0;
            public function reset(): void
            {
                $this->count++;
            }
        };

        $loop = new EmbedLoop();
        $loop->registerResetter($counter);
        $loop->run();

        $loop->dispatch('echo', msgpack_pack('a'));
        $loop->dispatch('echo', msgpack_pack('b'));
        $loop->dispatch('echo', msgpack_pack('c'));

        $this->assertSame(3, $counter->count);
    }

    public function testHandlerExceptionReturnsError(): void
    {
        $loop = new EmbedLoop();
        $loop->register('fail', fn() => throw new \RuntimeException('boom'));
        $loop->run();

        $response = msgpack_unpack($loop->dispatch('fail', msgpack_pack(null)));
        $this->assertArrayHasKey('error', $response);
        $this->assertSame('boom', $response['error']);
    }

    public function testUnknownMethodReturnsError(): void
    {
        $loop = new EmbedLoop();
        $loop->run();

        $response = msgpack_unpack($loop->dispatch('nonexistent', msgpack_pack(null)));
        $this->assertArrayHasKey('error', $response);
        $this->assertStringContainsString('method not found', $response['error']);
    }

    public function testGlobalFunctionDelegates(): void
    {
        $loop = new EmbedLoop();
        $loop->register('test.global', fn(mixed $p) => 'from_global');
        $loop->run();

        // Call via global function (same as Rust would)
        $response = folk_embed_dispatch('test.global', msgpack_pack(null));
        $this->assertSame('from_global', msgpack_unpack($response));
    }
}
