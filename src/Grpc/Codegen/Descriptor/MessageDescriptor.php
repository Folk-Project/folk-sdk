<?php

declare(strict_types=1);

namespace Folk\Sdk\Grpc\Codegen\Descriptor;

final readonly class MessageDescriptor
{
    /**
     * @param list<FieldDescriptor>   $fields
     * @param list<string>            $oneofs         oneof declaration names, by index
     * @param list<MessageDescriptor> $nestedMessages
     * @param list<EnumDescriptor>    $nestedEnums
     */
    public function __construct(
        public string $name,
        public array $fields,
        public array $oneofs,
        public array $nestedMessages,
        public array $nestedEnums,
        public bool $isMapEntry = false,
    ) {}
}
