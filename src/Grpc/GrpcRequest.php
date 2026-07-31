<?php

declare(strict_types=1);

namespace Folk\Sdk\Grpc;

/**
 * A decoded gRPC call as delivered by the Rust plugin.
 *
 * Two shapes (phase 87):
 *  - transcode (`encoding: "json"`): {@see $message} holds the decoded request as
 *    a native array; {@see $payload} is empty. The router hydrates it into a
 *    generated DTO.
 *  - transcode client-streaming / bidi (phase 94, #92): {@see $requests} holds the
 *    stream of inbound messages (each a decoded array); {@see $message} is null.
 *    The router hydrates each into a DTO and passes `iterable $requests` to the
 *    handler.
 *  - passthrough (default): {@see $payload} holds raw protobuf bytes; {@see
 *    $message} is null. The router hands the bytes to a legacy handler.
 */
final readonly class GrpcRequest
{
    /**
     * @param array<string, mixed>|null    $message  decoded request (unary transcode), else null
     * @param iterable<array<string,mixed>>|null $requests inbound message stream (client-streaming/bidi), else null
     */
    public function __construct(
        public string $service,
        public string $method,
        public string $payload,
        public Context $context,
        public ?array $message = null,
        public bool $transcode = false,
        public ?iterable $requests = null,
    ) {}

    /**
     * @param iterable<array<string,mixed>>|null $requests inbound message stream for a
     *   client-streaming / bidi call (built by the WorkerLoop from `folk_grpc_recv`),
     *   else null
     */
    public static function fromPayload(mixed $payload, ?iterable $requests = null): self
    {
        $data = is_array($payload) ? $payload : [];

        $service = self::asString($data['service'] ?? null);
        $method = self::asString($data['method'] ?? null);

        /** @var array<string, string|list<string>> $metadata */
        $metadata = isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : [];

        $requestId = isset($data['request_id']) && is_string($data['request_id'])
            ? $data['request_id'] : null;
        $timeout = isset($data['timeout_seconds']) && (is_int($data['timeout_seconds']) || is_float($data['timeout_seconds']))
            ? (float) $data['timeout_seconds'] : null;
        $peer = isset($data['peer_address']) && is_string($data['peer_address'])
            ? $data['peer_address'] : null;
        $authority = isset($data['authority']) && is_string($data['authority'])
            ? $data['authority'] : null;

        $context = new Context($metadata, $service, $method, $requestId, $timeout, $peer, $authority);

        // Transcode client-streaming / bidi (phase 94, #92): no single `message` —
        // inbound messages arrive via `$requests`. The router builds the handler's
        // `iterable $requests` from it.
        if (($data['encoding'] ?? null) === 'json' && ($data['client_streaming'] ?? false) === true) {
            return new self($service, $method, '', $context, null, true, $requests);
        }

        // Transcode envelope: structured message + encoding flag.
        if (($data['encoding'] ?? null) === 'json' && array_key_exists('message', $data)) {
            /** @var array<string, mixed> $message */
            $message = is_array($data['message']) ? $data['message'] : [];
            return new self($service, $method, '', $context, $message, true);
        }

        // Passthrough: base64 protobuf bytes (or an int-array fallback).
        $raw = $data['payload'] ?? '';
        if (is_string($raw)) {
            $decoded = base64_decode($raw, true);
            $raw = $decoded !== false ? $decoded : $raw;
        } elseif (is_array($raw)) {
            /** @var list<int> $ints */
            $ints = array_values(array_map('intval', $raw));
            $raw = pack('C*', ...$ints);
        } else {
            $raw = '';
        }

        return new self($service, $method, $raw, $context, null, false);
    }

    private static function asString(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
