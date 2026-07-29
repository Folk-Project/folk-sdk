<?php

declare(strict_types=1);

namespace Folk\Sdk\Worker;

use Folk\Sdk\Folk;
use Folk\Sdk\Grpc\GrpcModeHandler;
use Folk\Sdk\Grpc\GrpcRequest;
use Folk\Sdk\Http\HttpModeHandler;
use Folk\Sdk\Http\HttpRequest;
use Folk\Sdk\Jobs\JobsModeHandler;
use Folk\Sdk\Reset\ResettableInterface;

/**
 * Worker dispatch loop.
 *
 * Communicates with Rust via folk_worker_run() — zero-copy direct dispatch.
 */
final class WorkerLoop implements HandlerLoop
{
    /** @var array<string, callable(mixed): mixed> */
    private array $handlers = [];

    private ?HttpModeHandler $httpHandler = null;

    private ?JobsModeHandler $jobsHandler = null;

    /** @var list<ResettableInterface> */
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
            $request  = GrpcRequest::fromPayload($params);
            $response = $handler->call($request);

            // Server-streaming (phase 88b, #32): the handler returns a Traversable
            // of response messages (typically a generator). Drain it lazily,
            // framing each value as a `{__message: dto}` yield — the transport
            // emits each as its own gRPC message, so a large or unbounded result
            // never buffers in memory. A business status set during or after
            // iteration ends the stream with that gRPC code. The eventual return
            // value is ignored: the stream is already finalised via the yields
            // (`folk_grpc_yield`), and Folk emits the closing OK trailer.
            if ($response instanceof \Traversable) {
                foreach ($response as $message) {
                    Folk::grpcYield(json_encode(['__message' => $message], JSON_THROW_ON_ERROR));
                }
                $status = $request->context->getStatus();
                if ($status !== null) {
                    Folk::grpcYield(json_encode(
                        ['__grpc_status' => $status['code'], '__grpc_message' => $status['message']],
                        JSON_THROW_ON_ERROR,
                    ));
                }

                return null;
            }

            // A business status set via $context->setStatus() travels as a
            // normal return value; the gRPC plugin maps it to the gRPC code.
            $status = $request->context->getStatus();
            if ($status !== null) {
                return ['__grpc_status' => $status['code'], '__grpc_message' => $status['message']];
            }

            // Transcode tier: a structured `['__message' => array]` envelope the
            // plugin re-encodes via the output descriptor. Passthrough tier: raw
            // protobuf bytes, base64-framed for JSON transport.
            if (is_array($response)) {
                return $response;
            }

            return base64_encode($response ?? '');
        });
    }

    public function registerResetter(ResettableInterface $resetter): void
    {
        $this->resetters[] = $resetter;
    }

    public function register(string $method, callable $handler): void
    {
        $this->handlers[$method] = $handler;
    }

    public function run(): void
    {
        \folk_worker_run($this->prepareDispatch());
    }

    /**
     * Install the dispatch function and return its name (phase 79).
     *
     * Used by the fork-after-warm entry: call this in the master process after
     * registering handlers and BEFORE forking, then pass the returned name to
     * `Folk\Server::serveForked()`. The forked children inherit the registered
     * loop and dispatch function via copy-on-write.
     */
    public function prepareDispatch(): string
    {
        $GLOBALS['__folk_worker_loop'] = $this;
        require_once __DIR__ . '/dispatch_fn.php';

        return '__folk_dispatch';
    }

    /**
     * Direct dispatch for the zero-copy path (called from __folk_dispatch).
     *
     * Returns the handler result directly. On a fatal error, returns
     * ['__error' => message]; in dev mode (FOLK_DEV_MODE) the exception class
     * and stack trace are added as '__error_class' / '__error_trace' for the
     * server to surface — in production they are omitted to avoid leaking
     * internals.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function dispatchDirect(string $method, array $params): array
    {
        // Resetters must run between requests on BOTH the success and the error
        // path: a request that logs a user in and then throws would otherwise
        // leak its auth/session state into the next request on this warm worker
        // (folk-releases #86). `finally` guarantees the reset regardless of outcome.
        try {
            $result = $this->dispatch($method, $params);
            if ($result['error'] !== null) {
                $error = ['__error' => $result['error']];
                $throwable = $result['throwable'] ?? null;
                if ($throwable instanceof \Throwable && getenv('FOLK_DEV_MODE') !== false) {
                    $error['__error_class'] = $throwable::class;
                    $error['__error_trace'] = $throwable->getTraceAsString();
                }
                return $error;
            }
            return is_array($result['result']) ? $result['result'] : ['__result' => $result['result']];
        } finally {
            $this->runResetters();
        }
    }

    /**
     * @return array{error: ?string, result: mixed, throwable?: \Throwable}
     */
    private function dispatch(string $method, mixed $params): array
    {
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
            return ['error' => "method not found: {$method}", 'result' => null];
        }

        try {
            $result = ($this->handlers[$method])($params);
            return ['error' => null, 'result' => $result];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage(), 'result' => null, 'throwable' => $e];
        }
    }

    private function runResetters(): void
    {
        foreach ($this->resetters as $resetter) {
            try {
                $resetter->reset();
            } catch (\Throwable $e) {
                error_log('Folk resetter error: ' . $e->getMessage());
            }
        }
    }
}
