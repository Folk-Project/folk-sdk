<?php

/**
 * PHPStan stubs for the folk PHP extension (functions + Folk\Server class).
 */

namespace {
    function folk_version(): string {}
    function folk_is_worker_thread(): bool {}
    function folk_worker_run(string $callback): void {}
    function folk_call(string $method, string $payload): string {}
    function folk_request_id(): string {}
    function folk_write_head(int $status, string $headers_json): void {}
    function folk_write(string $data): void {}
    function folk_write_end(): void {}
    function folk_read(int $length = 8192): string {}
    function folk_read_all(): string {}
    function folk_next_part(): ?string {}
    function folk_part_read(int $length = 8192): string {}
    function folk_part_read_all(): string {}
}

namespace Folk {
    /**
     * Server lifecycle, provided by the folk extension at runtime.
     */
    class Server
    {
        public function __construct(string $configPath) {}

        /** Legacy single-process / ZTS-thread start (non-blocking). */
        public function start(): void {}

        /**
         * Fork-after-warm entry (phase 79): fork N worker processes and
         * supervise. Blocks until shutdown. `$dispatchFn` is the dispatch
         * function name returned by WorkerLoop::prepareDispatch().
         */
        public function serveForked(string $dispatchFn): void {}
    }
}
