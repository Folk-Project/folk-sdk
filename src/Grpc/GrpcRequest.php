<?php

declare(strict_types=1);

namespace Folk\Sdk\Grpc;

final readonly class GrpcRequest
{
    public function __construct(
        public string $service,
        public string $method,
        public string $payload,
        public Context $context,
    ) {}

    public static function fromPayload(mixed $payload): self
    {
        $raw = $payload['payload'] ?? '';
        // Base64-encoded protobuf bytes from Rust
        if (is_string($raw)) {
            $decoded = base64_decode($raw, true);
            if ($decoded !== false) {
                $raw = $decoded;
            }
        } elseif (is_array($raw)) {
            // Fallback: array of integers
            $raw = pack('C*', ...$raw);
        }

        $metadata = [];
        if (isset($payload['metadata']) && is_array($payload['metadata'])) {
            $metadata = $payload['metadata'];
        }

        return new self(
            service: $payload['service'] ?? '',
            method: $payload['method'] ?? '',
            payload: (string) $raw,
            context: new Context($metadata),
        );
    }
}
