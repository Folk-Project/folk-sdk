<?php

declare(strict_types=1);

namespace Folk\Sdk\Grpc\Codegen\Descriptor;

final readonly class MethodDescriptor
{
    public function __construct(
        public string $name,
        public string $inputType,
        public string $outputType,
        public bool $clientStreaming,
        public bool $serverStreaming,
    ) {}
}
