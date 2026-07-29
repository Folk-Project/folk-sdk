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

    /**
     * Yield one message in a gRPC server-streaming response (phase 88b, #32).
     * Present only when the build includes the gRPC plugin. `$message_json` is a
     * JSON envelope: `{"__message": <dto>}` for a response message, or
     * `{"__grpc_status": <code>, "__grpc_message": <msg>}` to end with a status.
     */
    function folk_grpc_yield(string $message_json): void {}

    // gRPC streaming client bridge (phase 88b, #32). Present only when the build
    // includes the gRPC plugin. Handle-based blocking stream over `folk_stream_*`.
    function folk_stream_open(string $method, string $payload): int {}
    function folk_stream_recv(int $handle): ?string {}
    function folk_stream_send(int $handle, string $payload): void {}
    function folk_stream_close_send(int $handle): void {}
    function folk_stream_close(int $handle): bool {}
    function folk_read(int $length = 8192): string {}
    function folk_read_all(): string {}
    function folk_next_part(): ?string {}
    function folk_part_read(int $length = 8192): string {}
    function folk_part_read_all(): string {}

    /**
     * Compile proto files to an encoded FileDescriptorSet (raw protobuf bytes).
     * Present only when the build includes the gRPC plugin (phase 87). Used by
     * the code generator and the `folk-server grpc:descriptors` submode.
     *
     * @param list<string> $paths Proto file paths, resolved relative to the CWD.
     */
    function folk_grpc_descriptors(array $paths): string {}
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
