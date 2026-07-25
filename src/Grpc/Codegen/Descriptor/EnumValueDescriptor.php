<?php

declare(strict_types=1);

namespace Folk\Sdk\Grpc\Codegen\Descriptor;

final readonly class EnumValueDescriptor
{
    public function __construct(
        public string $name,
        public int $number,
    ) {}
}
