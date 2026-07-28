<?php

declare(strict_types=1);

namespace Folk\Sdk\Grpc\Client;

use Folk\Sdk\Grpc\GrpcException;
use Folk\Sdk\Grpc\Hydrator;

/**
 * Runtime base for generated gRPC **client** stubs (phase 88). A generated
 * `{Service}Client` extends this and adds one typed method per unary RPC that
 * calls {@see call()} — the reverse of the server `*Interface`.
 *
 * The stub bakes two constants:
 *  - `CLIENT`  — the `[grpc.clients.<name>]` name; selects the upstream's
 *    descriptor pool, TLS, default deadline and retry policy in the Rust plugin.
 *  - `SERVICE` — the fully-qualified proto service name (`pkg.Service`).
 *
 * The wire path has no protoc / ext-grpc: {@see Hydrator::dehydrate()} turns the
 * request DTO into the transcoded array, `folk_call('grpc.client.call', …)` hands
 * it to the plugin (which encodes → tonic unary → decodes), and the response array
 * is hydrated back into a DTO. Metadata and per-call deadline are optional.
 */
abstract class GrpcClient
{
    /** Overridden by the generated stub with the `[grpc.clients.<name>]` name. */
    public const CLIENT = '';

    /** Overridden by the generated stub with the `pkg.Service` name. */
    public const SERVICE = '';

    /** @var callable(string, string): string */
    private $transport;

    /** @var array<string, list<string>> outbound metadata (multimap) */
    private array $metadata = [];

    private ?float $deadline = null;

    /**
     * @param string|null $address optional endpoint override (`host:port`); when
     *        null the plugin uses the address(es) from `[grpc.clients.<name>]`.
     * @param (callable(string, string): string)|null $transport RPC bridge; defaults
     *        to the `folk_call()` native function. Injectable for tests.
     */
    public function __construct(
        private readonly ?string $address = null,
        ?callable $transport = null,
        private readonly Hydrator $hydrator = new Hydrator(),
    ) {
        $this->transport = $transport ?? static fn (string $method, string $payload): string
            => (string) \folk_call($method, $payload);
    }

    /**
     * Return a copy of this client with an added outbound metadata entry (repeated
     * keys accumulate). Immutable: the receiver is unchanged.
     */
    public function withMetadata(string $key, string $value): static
    {
        $clone = clone $this;
        $clone->metadata[$key][] = $value;
        return $clone;
    }

    /**
     * Return a copy of this client with a per-call deadline (seconds). Immutable.
     */
    public function withDeadline(float $seconds): static
    {
        $clone = clone $this;
        $clone->deadline = $seconds;
        return $clone;
    }

    /**
     * Invoke a unary method, returning a hydrated response DTO.
     *
     * @template T of object
     * @param class-string<T> $responseClass
     * @return T
     * @throws GrpcException on any non-OK upstream/transport status
     */
    protected function call(string $method, object $request, string $responseClass): object
    {
        $message = $this->invoke($method, $this->hydrator->dehydrate($request));
        return $this->hydrator->hydrate($responseClass, $message);
    }

    /**
     * Invoke a unary method whose request/response types are not generatable DTOs,
     * exchanging plain transcoded arrays. Used by generated stubs for methods with
     * non-message or unresolved types.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     * @throws GrpcException on any non-OK upstream/transport status
     */
    protected function callArray(string $method, array $request): array
    {
        return $this->invoke($method, $request);
    }

    /**
     * Send the envelope over the bridge and return the response `message` array,
     * throwing {@see GrpcException} on a `__grpc_status`.
     *
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    private function invoke(string $method, array $message): array
    {
        $envelope = [
            'client' => static::CLIENT,
            'service' => static::SERVICE,
            'method' => $method,
            // A protobuf message and a metadata multimap are always JSON objects.
            // Cast so an EMPTY one encodes as `{}` rather than PHP's `[]` (which
            // the Rust side, expecting an object/map, would reject).
            'message' => (object) $message,
            'metadata' => (object) $this->metadata,
            'deadline_seconds' => $this->deadline,
        ];
        if ($this->address !== null) {
            $envelope['address'] = $this->address;
        }

        $json = json_encode($envelope, JSON_THROW_ON_ERROR);
        $raw = ($this->transport)('grpc.client.call', $json);

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new GrpcException(13, 'malformed grpc.client.call response');
        }

        if (array_key_exists('__grpc_status', $decoded)) {
            $status = $decoded['__grpc_status'];
            $msg = $decoded['__grpc_message'] ?? '';
            throw new GrpcException(
                is_int($status) ? $status : 13,
                is_string($msg) ? $msg : '',
            );
        }

        $result = $decoded['message'] ?? [];
        if (!is_array($result)) {
            return [];
        }
        /** @var array<string, mixed> $result */
        return $result;
    }
}
