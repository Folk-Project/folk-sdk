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
        return new self(
            service: $payload['service'] ?? '',
            method: $payload['method'] ?? '',
            payload: $payload['payload'] ?? '',
        );
    }
}
