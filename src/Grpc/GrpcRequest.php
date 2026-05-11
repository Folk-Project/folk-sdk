<?php

declare(strict_types=1);

namespace Folk\Sdk\Grpc;

final readonly class GrpcRequest
{
    public function __construct(
        public string $service,
        public string $method,
        public string $payload,
    ) {}

    public static function fromPayload(mixed $payload): self
    {
        $raw = $payload['payload'] ?? '';
        // MessagePack binary may arrive as array of integers
        if (is_array($raw)) {
            $raw = pack('C*', ...$raw);
        }

        return new self(
            service: $payload['service'] ?? '',
            method: $payload['method'] ?? '',
            payload: (string) $raw,
        );
    }
}
