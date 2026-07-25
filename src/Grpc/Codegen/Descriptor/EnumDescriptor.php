<?php

declare(strict_types=1);

namespace Folk\Sdk\Grpc\Codegen\Descriptor;

final readonly class EnumDescriptor
{
    /**
     * @param list<EnumValueDescriptor> $values
     */
    public function __construct(
        public string $name,
        public array $values,
    ) {}
}
