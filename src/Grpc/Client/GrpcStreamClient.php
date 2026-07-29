<?php

declare(strict_types=1);

namespace Folk\Sdk\Grpc\Client;

use Folk\Sdk\Grpc\GrpcException;
use Folk\Sdk\Grpc\Hydrator;

/**
 * Runtime base for generated gRPC client stubs that include **streaming** methods
 * (phase 88b, #32). Extends {@see GrpcClient}, so a single generated stub serves a
 * service that mixes unary and streaming RPCs: unary methods use the inherited
 * {@see GrpcClient::call()}, streaming methods use the helpers here.
 *
 * The wire path reuses the phase-87/88 transcoding but over the async bridge: each
 * call opens `grpc.client.stream` via {@see StreamTransport}, sending/receiving one
 * transcoded JSON frame per message. Response frames hydrate back into DTOs; a
 * non-OK trailing status raises {@see GrpcException}.
 *
 * DX:
 * ```php
 * foreach ($client->Watch($req) as $update) { … }      // server-streaming
 * $summary = $client->Upload($chunks);                  // client-streaming
 * foreach ($client->Converse($outgoing) as $incoming) { … } // bidi
 * ```
 *
 * **v1 bidi is serialized**: all outbound messages are sent and half-closed before
 * inbound messages are drained (a single PHP worker thread blocks on `recv`). True
 * concurrent bidi is a later increment.
 */
abstract class GrpcStreamClient extends GrpcClient
{
    private readonly StreamTransport $streamTransport;

    /**
     * @param string|null $address optional `host:port` endpoint override.
     * @param (callable(string, string): string)|null $transport unary RPC bridge
     *        (see {@see GrpcClient}).
     * @param StreamTransport|null $streamTransport streaming bridge; defaults to
     *        the native `folk_stream_*` functions. Injectable for tests.
     */
    public function __construct(
        ?string $address = null,
        ?callable $transport = null,
        Hydrator $hydrator = new Hydrator(),
        ?StreamTransport $streamTransport = null,
    ) {
        parent::__construct($address, $transport, $hydrator);
        $this->streamTransport = $streamTransport ?? new NativeStreamTransport();
    }

    /**
     * Server-streaming: one request DTO → a lazy stream of response DTOs.
     *
     * @template T of object
     * @param class-string<T> $responseClass
     * @return \Generator<int, T, mixed, void>
     */
    protected function serverStream(string $method, object $request, string $responseClass): \Generator
    {
        $handle = $this->openStream($method, 'server_streaming', $this->hydrator->dehydrate($request));
        try {
            while (($raw = $this->streamTransport->recv($handle)) !== null) {
                $message = $this->frameMessage($raw);
                if ($message !== null) {
                    yield $this->hydrator->hydrate($responseClass, $message);
                }
            }
        } finally {
            $this->streamTransport->close($handle);
        }
    }

    /**
     * Client-streaming: a stream of request DTOs → one response DTO.
     *
     * @template T of object
     * @param iterable<object> $requests
     * @param class-string<T> $responseClass
     * @return T
     * @throws GrpcException on a non-OK status or a missing response
     */
    protected function clientStream(string $method, iterable $requests, string $responseClass): object
    {
        $handle = $this->openStream($method, 'client_streaming', []);
        try {
            $this->sendAll($handle, $requests);
            $this->streamTransport->closeSend($handle);

            $raw = $this->streamTransport->recv($handle);
            if ($raw === null) {
                throw new GrpcException(13, 'client-streaming: upstream closed without a response');
            }
            $message = $this->frameMessage($raw);
            if ($message === null) {
                throw new GrpcException(13, 'client-streaming: no response message');
            }

            return $this->hydrator->hydrate($responseClass, $message);
        } finally {
            $this->streamTransport->close($handle);
        }
    }

    /**
     * Bidirectional streaming: a stream of request DTOs ↔ a stream of response
     * DTOs. **v1 serialized** — see the class docblock.
     *
     * @template T of object
     * @param iterable<object> $requests
     * @param class-string<T> $responseClass
     * @return \Generator<int, T, mixed, void>
     */
    protected function bidiStream(string $method, iterable $requests, string $responseClass): \Generator
    {
        $handle = $this->openStream($method, 'bidi', []);
        try {
            $this->sendAll($handle, $requests);
            $this->streamTransport->closeSend($handle);

            while (($raw = $this->streamTransport->recv($handle)) !== null) {
                $message = $this->frameMessage($raw);
                if ($message !== null) {
                    yield $this->hydrator->hydrate($responseClass, $message);
                }
            }
        } finally {
            $this->streamTransport->close($handle);
        }
    }

    /**
     * Untyped fallbacks for methods whose message types are not generatable DTOs —
     * exchange plain transcoded arrays. Mirror the `*Array` unary helpers.
     *
     * @param array<string, mixed> $request
     * @return \Generator<int, array<string, mixed>, mixed, void>
     */
    protected function serverStreamArray(string $method, array $request): \Generator
    {
        $handle = $this->openStream($method, 'server_streaming', $request);
        try {
            while (($raw = $this->streamTransport->recv($handle)) !== null) {
                $message = $this->frameMessage($raw);
                if ($message !== null) {
                    yield $message;
                }
            }
        } finally {
            $this->streamTransport->close($handle);
        }
    }

    /**
     * @param iterable<array<string, mixed>> $requests
     * @return array<string, mixed>
     * @throws GrpcException on a non-OK status or a missing response
     */
    protected function clientStreamArray(string $method, iterable $requests): array
    {
        $handle = $this->openStream($method, 'client_streaming', []);
        try {
            $this->sendAll($handle, $requests);
            $this->streamTransport->closeSend($handle);

            $raw = $this->streamTransport->recv($handle);
            if ($raw === null) {
                throw new GrpcException(13, 'client-streaming: upstream closed without a response');
            }
            $message = $this->frameMessage($raw);
            if ($message === null) {
                throw new GrpcException(13, 'client-streaming: no response message');
            }

            return $message;
        } finally {
            $this->streamTransport->close($handle);
        }
    }

    /**
     * @param iterable<array<string, mixed>> $requests
     * @return \Generator<int, array<string, mixed>, mixed, void>
     */
    protected function bidiStreamArray(string $method, iterable $requests): \Generator
    {
        $handle = $this->openStream($method, 'bidi', []);
        try {
            $this->sendAll($handle, $requests);
            $this->streamTransport->closeSend($handle);

            while (($raw = $this->streamTransport->recv($handle)) !== null) {
                $message = $this->frameMessage($raw);
                if ($message !== null) {
                    yield $message;
                }
            }
        } finally {
            $this->streamTransport->close($handle);
        }
    }

    /**
     * Dehydrate and send every request DTO (or raw array) on the outbound half.
     *
     * @param iterable<object|array<string, mixed>> $requests
     */
    private function sendAll(int $handle, iterable $requests): void
    {
        foreach ($requests as $request) {
            $message = is_array($request) ? $request : $this->hydrator->dehydrate($request);
            $this->streamTransport->send(
                $handle,
                json_encode((object) $message, JSON_THROW_ON_ERROR),
            );
        }
    }

    /**
     * Open the `grpc.client.stream` for `$method` with the streaming envelope.
     *
     * @param array<string, mixed> $message initial request (server-streaming) or
     *        empty for client-/bidi-streaming (messages travel via `send`).
     */
    private function openStream(string $method, string $kind, array $message): int
    {
        $envelope = [
            'client' => static::CLIENT,
            'service' => static::SERVICE,
            'method' => $method,
            'kind' => $kind,
            // A protobuf message and a metadata multimap are always JSON objects —
            // cast so an empty one encodes as `{}`, not `[]` (matches GrpcClient).
            'message' => (object) $message,
            'metadata' => (object) $this->metadata,
            'deadline_seconds' => $this->deadline,
        ];
        if ($this->address !== null) {
            $envelope['address'] = $this->address;
        }

        return $this->streamTransport->open(
            'grpc.client.stream',
            json_encode($envelope, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Decode one inbound frame. Returns the message array for a `{"message": …}`
     * frame; null for an OK (`code 0`) status marker; throws {@see GrpcException}
     * for any non-OK trailing status.
     *
     * @return array<string, mixed>|null
     * @throws GrpcException
     */
    private function frameMessage(string $raw): ?array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new GrpcException(13, 'malformed grpc.client.stream frame');
        }

        if (array_key_exists('__grpc_status', $decoded)) {
            $code = $decoded['__grpc_status'];
            $code = is_int($code) ? $code : 13;
            if ($code === 0) {
                return null; // OK marker — no message, not an error
            }
            $msg = $decoded['__grpc_message'] ?? '';
            throw new GrpcException($code, is_string($msg) ? $msg : '');
        }

        $message = $decoded['message'] ?? [];

        /** @var array<string, mixed> */
        return is_array($message) ? $message : [];
    }
}
