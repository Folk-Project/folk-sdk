<?php

declare(strict_types=1);

namespace Folk\Sdk\Worker;

use Folk\Sdk\Grpc\GrpcModeHandler;
use Folk\Sdk\Grpc\GrpcRequest;
use Folk\Sdk\Http\HttpModeHandler;
use Folk\Sdk\Http\HttpRequest;
use Folk\Sdk\Jobs\JobsModeHandler;
use Folk\Sdk\Protocol\FrameReader;
use Folk\Sdk\Protocol\FrameWriter;
use Folk\Sdk\Protocol\RpcMessage;

/**
 * Worker dispatch loop.
 *
 * In extension mode (folk.so loaded), communicates via folk_worker_recv/send.
 * In pipe mode (legacy), reads/writes FD 3/4 with msgpack-RPC framing.
 */
final class WorkerLoop implements HandlerLoop
{
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

    /**
     * Register a handler for an RPC method.
     */
    public function register(string $method, callable $handler): void
    {
        $this->handlers[$method] = $handler;
    }

    /**
     * Run the worker loop until shutdown.
     *
     * Detects extension mode automatically.
     */
    public function run(): void
    {
        if (function_exists('folk_worker_recv')) {
            $this->runExtension();
        } else {
            $this->runPipe();
        }
    }

    /**
     * Extension mode: communicate via folk_worker_recv/send (channels, zero IPC).
     */
    private function runExtension(): void
    {
        // Signal ready.
        \folk_worker_ready();

        // Main dispatch loop.
        while (true) {
            $msg = \folk_worker_recv();
            if ($msg === null) {
                break; // shutdown
            }

            [$method, $paramsBin] = $msg;
            $params = \msgpack_unpack($paramsBin);

            $result = $this->dispatch($method, $params);

            if ($result['error'] !== null) {
                \folk_worker_send_error($result['error']);
            } else {
                \folk_worker_send(\msgpack_pack($result['result']));
            }

            $this->runResetters();
        }
    }

    /**
     * Pipe mode (legacy): communicate via FD 3/4 with msgpack-RPC framing.
     */
    private function runPipe(): void
    {
        $taskFd    = (int) getenv('FOLK_TASK_FD');
        $controlFd = (int) getenv('FOLK_CONTROL_FD');

        if ($taskFd === 0 || $controlFd === 0) {
            fwrite(STDERR, "folk-worker: FOLK_TASK_FD or FOLK_CONTROL_FD not set\n");
            exit(1);
        }

        $task    = self::openFd($taskFd);
        $control = self::openFd($controlFd);

        stream_set_blocking($task, true);
        stream_set_blocking($control, true);

        $taskReader    = new FrameReader($task);
        $taskWriter    = new FrameWriter($task);
        $controlWriter = new FrameWriter($control);

        $controlWriter->write(RpcMessage::notify('control.ready', ['pid' => getmypid()]));

        while (true) {
            $taskMsg = $taskReader->read();
            if ($taskMsg === null) {
                break;
            }

            if ($taskMsg->type !== RpcMessage::TYPE_REQUEST) {
                continue;
            }

            $response = $this->handleRequest($taskMsg);
            $taskWriter->write($response);
            $this->runResetters();
        }

        fclose($task);
        fclose($control);
    }

    /**
     * Dispatch a request to the appropriate handler.
     *
     * @return array{error: ?string, result: mixed}
     */
    private function dispatch(string $method, mixed $params): array
    {
        // Auto-route 'dispatch' to registered handlers.
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
                if (method_exists($resetter, 'reset')) {
                    $resetter->reset();
                }
            } catch (\Throwable $e) {
                error_log('Folk resetter error: ' . $e->getMessage());
            }
        }
    }

    /**
     * @return resource
     */
    private static function openFd(int $fd)
    {
        try {
            $stream = @fopen('/dev/fd/' . $fd, 'r+b');
            if ($stream !== false) {
                return $stream;
            }
        } catch (\Throwable) {}

        try {
            $stream = @fopen('php://fd/' . $fd, 'r+b');
            if ($stream !== false) {
                return $stream;
            }
        } catch (\Throwable) {}

        fwrite(STDERR, "folk-worker: failed to open fd {$fd}\n");
        exit(1);
    }

    private function handleRequest(RpcMessage $request): RpcMessage
    {
        $method = $request->method ?? '';
        $params = $request->params;
        $msgid  = $request->msgid ?? 0;

        $result = $this->dispatch($method, $params);

        if ($result['error'] !== null) {
            return RpcMessage::response(
                $msgid,
                ['code' => -32603, 'message' => $result['error']],
                null
            );
        }

        return RpcMessage::response($msgid, null, $result['result']);
    }
}
