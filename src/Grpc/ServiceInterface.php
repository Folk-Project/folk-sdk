<?php

declare(strict_types=1);

namespace Folk\Sdk\Grpc;

/**
 * Base interface for gRPC service handlers.
 *
 * Generated interfaces should extend this and define:
 * - const NAME = "package.ServiceName"
 * - Typed methods matching gRPC methods
 *
 * Compatible with Spiral/RoadRunner generated interfaces.
 */
interface ServiceInterface
{
    // Implementations must define: public const NAME = "...";
}
