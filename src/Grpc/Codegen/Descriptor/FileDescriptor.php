<?php

declare(strict_types=1);

namespace Folk\Sdk\Grpc\Codegen\Descriptor;

final readonly class FileDescriptor
{
    /**
     * @param list<MessageDescriptor> $messages top-level messages
     * @param list<EnumDescriptor>    $enums    top-level enums
     * @param list<ServiceDescriptor> $services
     */
    public function __construct(
        public string $name,
        public string $package,
        public string $syntax,
        public array $messages,
        public array $enums,
        public array $services,
    ) {}
}
