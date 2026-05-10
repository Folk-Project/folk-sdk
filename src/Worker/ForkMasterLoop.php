<?php

declare(strict_types=1);

namespace Folk\Sdk\Worker;

use Folk\Sdk\Protocol\FrameReader;
use Folk\Sdk\Protocol\FrameWriter;
use Folk\Sdk\Protocol\RpcMessage;
use Folk\Sdk\Protocol\ScmRights;

/**
 * Fork-mode master process.
 *
 * Boots the framework once (warm OPcache), sends control.fork-ready,
 * then loops: receives FDs via SCM_RIGHTS, forks a child for each
 * worker slot, and the child enters WorkerLoop dispatch.
 */
final class ForkMasterLoop
{
    private ?FrameReader $controlReader;
    private ?FrameWriter $controlWriter;
    private ?\Socket $taskSocket;

    /** @var resource|null */
    private $controlStream;

    /** @var ?\Closure(): void */
    private ?\Closure $masterBootHook;

    /** @var ?\Closure(WorkerLoop): void */
    private ?\Closure $workerBootHook;

    /**
     * @param ?\Closure(): void $masterBootHook Called before fork-ready (boot framework)
     * @param ?\Closure(WorkerLoop): void $workerBootHook Called in each child before dispatch
     */
    public function __construct(?\Closure $masterBootHook = null, ?\Closure $workerBootHook = null)
    {
        $this->masterBootHook = $masterBootHook;
        $this->workerBootHook = $workerBootHook;
    }

    /**
     * Run the fork master loop.
     */
    public function run(): void
    {
        $this->checkExtensions();
        $this->openChannels();

        // Call master boot hook (warms OPcache, autoloader, framework state)
        if ($this->masterBootHook !== null) {
            ($this->masterBootHook)();
        }

        // Signal to Rust that the master is ready to accept fork requests
        $this->controlWriter->write(
            RpcMessage::notify('control.fork-ready', ['pid' => getmypid()])
        );

        // Install SIGCHLD handler to reap children
        pcntl_signal(SIGCHLD, static function (): void {
            while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {
                // reaped
            }
        });

        // Install SIGTERM handler for graceful shutdown
        $running = true;
        pcntl_signal(SIGTERM, static function () use (&$running): void {
            $running = false;
        });

        // Fork loop: receive FDs, fork child, repeat
        while ($running) {
            pcntl_signal_dispatch();

            if (!$running) {
                break;
            }

            // Receive 2 FDs (task + control for child) via SCM_RIGHTS
            try {
                $fds = ScmRights::receiveFds($this->taskSocket);
            } catch (\RuntimeException) {
                // Peer closed or error — exit master loop
                break;
            }

            if (count($fds) < 2) {
                fwrite(STDERR, "folk-master: expected 2 FDs, received " . count($fds) . "\n");
                continue;
            }

            $childTaskFd = $fds[0];
            $childControlFd = $fds[1];

            // Read fork.spawn command from control channel
            $msg = $this->controlReader->read();
            if ($msg === null) {
                break; // EOF
            }

            if ($msg->type !== RpcMessage::TYPE_NOTIFY || $msg->method !== 'fork.spawn') {
                fwrite(STDERR, "folk-master: expected fork.spawn, got {$msg->method}\n");
                continue;
            }

            // Fork!
            $childPid = pcntl_fork();

            if ($childPid === -1) {
                fwrite(STDERR, "folk-master: pcntl_fork() failed\n");
                continue;
            }

            if ($childPid === 0) {
                // === CHILD PROCESS ===
                $this->runChild($childTaskFd, $childControlFd);
                exit(0);
            }

            // === PARENT PROCESS ===
            // Child FDs remain open in parent — bounded by worker pool size.
            // Notify Rust of the child PID
            $this->controlWriter->write(
                RpcMessage::notify('fork.spawned', ['pid' => $childPid])
            );
        }

        // Cleanup: wait for remaining children
        while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {
            // reaped
        }

        if ($this->controlStream !== null) {
            fclose($this->controlStream);
        }
    }

    private function runChild(int $taskFd, int $controlFd): void
    {
        // Reset signal handlers in child
        pcntl_signal(SIGCHLD, SIG_DFL);
        pcntl_signal(SIGTERM, SIG_DFL);

        // Set environment for WorkerLoop
        putenv("FOLK_TASK_FD={$taskFd}");
        putenv("FOLK_CONTROL_FD={$controlFd}");

        // Close master's control channel in child
        if ($this->controlStream !== null) {
            fclose($this->controlStream);
            $this->controlStream = null;
        }

        $loop = new WorkerLoop();

        // Call worker boot hook (register handlers, resetters)
        if ($this->workerBootHook !== null) {
            ($this->workerBootHook)($loop);
        }

        $loop->run();
    }

    private function openChannels(): void
    {
        $taskFd = (int) getenv('FOLK_TASK_FD');
        $controlFd = (int) getenv('FOLK_CONTROL_FD');

        if ($taskFd === 0 || $controlFd === 0) {
            fwrite(STDERR, "folk-master: FOLK_TASK_FD or FOLK_CONTROL_FD not set\n");
            exit(1);
        }

        // Task channel: import as Socket for SCM_RIGHTS receiving
        $taskStream = @fopen('php://fd/' . $taskFd, 'r+b')
                      ?: fopen('/dev/fd/' . $taskFd, 'r+b');
        if ($taskStream === false) {
            fwrite(STDERR, "folk-master: failed to open task fd {$taskFd}\n");
            exit(1);
        }

        $this->taskSocket = socket_import_stream($taskStream);
        if ($this->taskSocket === false) {
            fwrite(STDERR, "folk-master: socket_import_stream failed for task fd\n");
            exit(1);
        }

        // Control channel: standard stream for framed RPC
        $this->controlStream = @fopen('php://fd/' . $controlFd, 'r+b')
                               ?: fopen('/dev/fd/' . $controlFd, 'r+b');
        if ($this->controlStream === false) {
            fwrite(STDERR, "folk-master: failed to open control fd {$controlFd}\n");
            exit(1);
        }

        stream_set_blocking($this->controlStream, true);

        $this->controlReader = new FrameReader($this->controlStream);
        $this->controlWriter = new FrameWriter($this->controlStream);
    }

    private function checkExtensions(): void
    {
        if (!extension_loaded('pcntl')) {
            fwrite(STDERR, "folk-master: fork mode requires the 'pcntl' PHP extension\n");
            exit(1);
        }
        if (!extension_loaded('sockets')) {
            fwrite(STDERR, "folk-master: fork mode requires the 'sockets' PHP extension\n");
            exit(1);
        }
    }
}
