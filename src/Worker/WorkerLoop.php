<?php

declare(strict_types=1);

namespace Folk\Sdk\Worker;

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
            $request = GrpcRequest::fromPayload($params);
            $response = $handler->call($request->service, $request->method, $request->payload, $request->context);
            return base64_encode($response);
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
        $GLOBALS['__folk_worker_loop'] = $this;
        require_once __DIR__ . '/dispatch_fn.php';
        \folk_worker_run('__folk_dispatch');
    }

    /**
     * Direct dispatch for the zero-copy path (called from __folk_dispatch).
     *
     * Returns the handler result directly. On error, returns ['__error' => message].
     */
    public function dispatchDirect(string $method, array $params): array
    {
        $result = $this->dispatch($method, $params);
        if ($result['error'] !== null) {
            return ['__error' => $result['error']];
        }
        $this->runResetters();
        return is_array($result['result']) ? $result['result'] : ['__result' => $result['result']];
    }

    /**
     * @return array{error: ?string, result: mixed}
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
            return ['error' => $e->getMessage(), 'result' => null];
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
