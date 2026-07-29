<?php

declare(strict_types=1);

namespace Folk\Sdk\Grpc\Client;

/**
 * Default {@see StreamTransport} backed by the native `folk_stream_*` functions
 * the Folk extension exposes when the gRPC plugin is in the build (phase 88b).
 */
final class NativeStreamTransport implements StreamTransport
{
    public function open(string $method, string $payload): int
    {
        return \folk_stream_open($method, $payload);
    }

    public function recv(int $handle): ?string
    {
        return \folk_stream_recv($handle);
    }

    public function send(int $handle, string $payload): void
    {
        \folk_stream_send($handle, $payload);
    }

    public function closeSend(int $handle): void
    {
        \folk_stream_close_send($handle);
    }

    public function close(int $handle): bool
    {
        return \folk_stream_close($handle);
    }
}
