<?php

declare(strict_types=1);

namespace Folk\Sdk\Grpc\Client;

/**
 * The handle-based blocking stream bridge used by {@see GrpcStreamClient} (phase
 * 88b, #32). Each method maps 1:1 to a native `folk_stream_*` extension function;
 * the interface exists so tests can drive the client without the extension.
 *
 * A handle is an opaque per-worker id. `recv` blocks until the next inbound frame
 * (a JSON `{"message": …}` or `{"__grpc_status": …}`), returning null at the OK
 * end of the stream. `send` / `closeSend` drive the outbound half (client- and
 * bidi-streaming); `close` cancels/releases the stream.
 */
interface StreamTransport
{
    /**
     * Open a stream for `$method` with the JSON `$payload` envelope; returns the
     * handle. `$method` is always `grpc.client.stream` for the gRPC client.
     */
    public function open(string $method, string $payload): int;

    /** Block for the next inbound frame (JSON bytes); null at OK end-of-stream. */
    public function recv(int $handle): ?string;

    /** Send one outbound message (JSON DTO bytes). */
    public function send(int $handle, string $payload): void;

    /** Half-close the outbound half (no more `send`s). */
    public function closeSend(int $handle): void;

    /** Cancel/release the stream. Returns whether a handle was present. */
    public function close(int $handle): bool;
}
