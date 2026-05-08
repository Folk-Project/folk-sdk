<?php

declare(strict_types=1);

namespace Folk\Sdk\Worker;

use Folk\Sdk\Http\HttpModeHandler;
use Folk\Sdk\Http\HttpRequest;
use Folk\Sdk\Jobs\JobsModeHandler;
use Folk\Sdk\Protocol\FrameReader;
use Folk\Sdk\Protocol\FrameWriter;
use Folk\Sdk\Protocol\RpcMessage;

/**
 * Minimal pipe-mode worker loop.
 *
 * Reads FOLK_TASK_FD and FOLK_CONTROL_FD from the environment,
 * sends control.ready on the control channel, then dispatches
 * task-channel requests in a loop until control.shutdown is received.
 *
 * Phase 5: only the 'echo' method is registered.
 * Phases 6+: HTTP, Jobs, gRPC handlers will be registered via the hook system.
 */
final class WorkerLoop
{
    /** @var array<string, callable(mixed): mixed> */
    private array $handlers = [];

    private ?HttpModeHandler $httpHandler = null;

    private ?JobsModeHandler $jobsHandler = null;

    /** @var object[] */
    private array $resetters = [];

    public function __construct()
    {
        // Register built-in handlers.
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

    public function registerResetter(object $resetter): void
    {
        $this->resetters[] = $resetter;
    }

    /**
     * Register a handler for an RPC method.
     *
     * @param string $method Method name (e.g. 'http.handle')
     * @param callable(mixed): mixed $handler Called with $params, returns $result
     */
    public function register(string $method, callable $handler): void
    {
        $this->handlers[$method] = $handler;
    }

    /**
     * Run the worker loop until shutdown is signaled.
     */
    public function run(): void
    {
        $taskFd    = (int) getenv('FOLK_TASK_FD');
        $controlFd = (int) getenv('FOLK_CONTROL_FD');

        if ($taskFd === 0 || $controlFd === 0) {
            fwrite(STDERR, "folk-worker: FOLK_TASK_FD or FOLK_CONTROL_FD not set\n");
            exit(1);
        }

        $task    = fopen('php://fd/' . $taskFd, 'r+b');
        $control = fopen('php://fd/' . $controlFd, 'r+b');

        if ($task === false || $control === false) {
            fwrite(STDERR, "folk-worker: failed to open file descriptors\n");
            exit(1);
        }

        stream_set_blocking($task, true);
        stream_set_blocking($control, true);

        $taskReader    = new FrameReader($task);
        $taskWriter    = new FrameWriter($task);
        $controlWriter = new FrameWriter($control);

        // Send control.ready.
        $controlWriter->write(RpcMessage::notify('control.ready', ['pid' => getmypid()]));

        // Main dispatch loop.
        while (true) {
            $taskMsg = $taskReader->read();
            if ($taskMsg === null) {
                // EOF on task channel: server closed the socket. Exit cleanly.
                break;
            }

            if ($taskMsg->type !== RpcMessage::TYPE_REQUEST) {
                // Ignore non-request frames (shouldn't happen in normal operation).
                continue;
            }

            $response = $this->handleRequest($taskMsg);
            $taskWriter->write($response);
            $this->runResetters();
        }

        fclose($task);
        fclose($control);
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

    private function handleRequest(RpcMessage $request): RpcMessage
    {
        $method = $request->method;
        $params = $request->params;
        $msgid  = $request->msgid;

        if (!isset($this->handlers[$method])) {
            return RpcMessage::response(
                $msgid,
                ['code' => -32601, 'message' => "method not found: {$method}"],
                null
            );
        }

        try {
            $result = ($this->handlers[$method])($params);
            return RpcMessage::response($msgid, null, $result);
        } catch (\Throwable $e) {
            return RpcMessage::response(
                $msgid,
                ['code' => -32603, 'message' => $e->getMessage()],
                null
            );
        }
    }
}
