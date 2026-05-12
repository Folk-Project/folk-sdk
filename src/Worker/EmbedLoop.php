<?php

declare(strict_types=1);

namespace Folk\Sdk\Worker;

use Folk\Sdk\Grpc\GrpcModeHandler;
use Folk\Sdk\Grpc\GrpcRequest;
use Folk\Sdk\Http\HttpModeHandler;
use Folk\Sdk\Http\HttpRequest;
use Folk\Sdk\Jobs\JobsModeHandler;

/**
 * Embed-mode worker loop.
 *
 * Same handler registration API as WorkerLoop, but run() does NOT block.
 * Instead it registers a global PHP function that Rust calls via FFI
 * for each request.
 *
 * The Rust embed runtime drives the request loop:
 *   1. Rust receives HTTP/gRPC/jobs request
 *   2. Rust calls folk_embed_dispatch($method, $params) via FFI
 *   3. This class dispatches to the registered handler
 *   4. Returns serialized response to Rust
 */
final class EmbedLoop implements HandlerLoop
{
    /** Singleton — Rust calls the global function which delegates to this. */
    public static ?self $instance = null;

    /** @var array<string, callable(mixed): mixed> */
    private array $handlers = [];

    private ?HttpModeHandler $httpHandler = null;

    private ?JobsModeHandler $jobsHandler = null;

    /** @var list<object> */
    private array $resetters = [];

    public function __construct()
    {
        $this->handlers['echo'] = static fn(mixed $params): mixed => $params;
    }

    public function registerHttpHandler(HttpModeHandler $handler): void
    {
        $this->httpHandler = $handler;
        $this->register('http.handle', function (mixed $params): mixed {
            if ($this->httpHandler === null) {
                throw new \RuntimeException('no http handler registered');
            }
            $request  = HttpRequest::fromPayload($params);
            $response = $this->httpHandler->handle($request);
            return $response->toPayload();
        });
    }

    public function registerJobsHandler(JobsModeHandler $handler): void
    {
        $this->jobsHandler = $handler;
        $this->register('jobs.process', function (mixed $params): mixed {
            if ($this->jobsHandler === null) {
                throw new \RuntimeException('no jobs handler registered');
            }
            return $this->jobsHandler->process($params);
        });
    }

    public function registerGrpcHandler(GrpcModeHandler $handler): void
    {
        $this->register('grpc.call', function (mixed $params) use ($handler): mixed {
            $request = GrpcRequest::fromPayload($params);
            return $handler->call($request->service, $request->method, $request->payload, $request->context);
        });
    }

    public function registerResetter(object $resetter): void
    {
        $this->resetters[] = $resetter;
    }

    public function register(string $method, callable $handler): void
    {
        $this->handlers[$method] = $handler;
    }

    /**
     * Register the global dispatch function and return immediately.
     *
     * Unlike WorkerLoop::run() which blocks forever reading from FDs,
     * this method sets up the dispatch callback and returns. Rust drives
     * the request loop via FFI.
     */
    public function run(): void
    {
        self::$instance = $this;

        // Register the global function that Rust calls per request.
        // This is defined at the global scope so Rust can find it by name.
        if (!function_exists('folk_embed_dispatch')) {
            eval('
                function folk_embed_dispatch(string $method, string $params): string {
                    return \Folk\Sdk\Worker\EmbedLoop::handleFromRust($method, $params);
                }
            ');
        }
    }

    /**
     * Called by Rust via FFI for each request.
     *
     * @param string $method RPC method name (e.g. "http.handle")
     * @param string $params Raw msgpack binary
     * @return string Raw msgpack binary response
     */
    public static function handleFromRust(string $method, string $params): string
    {
        if (self::$instance === null) {
            return msgpack_pack(['error' => 'EmbedLoop not initialized']);
        }

        return self::$instance->dispatch($method, $params);
    }

    /**
     * Dispatch a request to the registered handler.
     */
    public function dispatch(string $method, string $rawParams): string
    {
        try {
            $params = msgpack_unpack($rawParams);
        } catch (\Throwable) {
            $params = $rawParams;
        }

        // Route "dispatch" to first available handler
        if ($method === 'dispatch' && !isset($this->handlers['dispatch'])) {
            if (isset($this->handlers['http.handle'])) {
                $method = 'http.handle';
            } elseif (isset($this->handlers['jobs.process'])) {
                $method = 'jobs.process';
            } elseif (isset($this->handlers['grpc.call'])) {
                $method = 'grpc.call';
            }
        }

        if (!isset($this->handlers[$method])) {
            return msgpack_pack(['error' => "method not found: {$method}"]);
        }

        try {
            $result = ($this->handlers[$method])($params);
            $response = msgpack_pack($result);
        } catch (\Throwable $e) {
            $response = msgpack_pack(['error' => $e->getMessage()]);
        }

        $this->runResetters();

        return $response;
    }

    private function runResetters(): void
    {
        foreach ($this->resetters as $resetter) {
            try {
                if (method_exists($resetter, 'reset')) {
                    $resetter->reset();
                }
            } catch (\Throwable $e) {
                error_log('Folk resetter error: ' . $e->getMessage());
            }
        }
    }
}
