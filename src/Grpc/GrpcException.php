<?php

declare(strict_types=1);

namespace Folk\Sdk\Grpc;

/**
 * Thrown when an upstream gRPC call (Folk as client, phase 88) returns a non-OK
 * status — either a business status the upstream set, or a transport failure the
 * plugin mapped to a canonical code (`UNAVAILABLE(14)` for an unreachable
 * upstream, `DEADLINE_EXCEEDED(4)` for an expired deadline).
 *
 * The numeric {@see status()} is a canonical gRPC code (google.rpc.Code); it is
 * also available via the standard `getCode()`.
 */
final class GrpcException extends \RuntimeException
{
    public function __construct(
        private readonly int $status,
        string $message = '',
    ) {
        parent::__construct($message, $status);
    }

    /** The canonical gRPC status code (google.rpc.Code). */
    public function status(): int
    {
        return $this->status;
    }
}
