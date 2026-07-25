<?php

declare(strict_types=1);

namespace Folk\Sdk\Grpc\Codegen\Descriptor;

use Folk\Sdk\Grpc\Codegen\ProtoType;

/**
 * One field of a message. `type`/`label` are the raw `FieldDescriptorProto` enum
 * values (see {@see \Folk\Sdk\Grpc\Codegen\ProtoType}); `typeName` is the
 * fully-qualified `.pkg.Message`/`.pkg.Enum` for message/enum fields.
 */
final readonly class FieldDescriptor
{
    public function __construct(
        public string $name,
        public int $number,
        public int $label,
        public int $type,
        public string $typeName,
        public ?int $oneofIndex,
        public bool $proto3Optional,
    ) {}

    public function isRepeated(): bool
    {
        return $this->label === ProtoType::LABEL_REPEATED;
    }
}
