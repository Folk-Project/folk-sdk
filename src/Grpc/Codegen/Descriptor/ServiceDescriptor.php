<?php

declare(strict_types=1);

namespace Folk\Sdk\Grpc\Codegen\Descriptor;

final readonly class ServiceDescriptor
{
    /**
     * @param list<MethodDescriptor> $methods
     */
    public function __construct(
        public string $name,
        public array $methods,
    ) {}
}
