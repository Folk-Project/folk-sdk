<?php

declare(strict_types=1);

namespace Folk\Sdk\Grpc\Codegen;

use Folk\Sdk\Grpc\Codegen\Descriptor\FieldDescriptor;

/**
 * Maps a proto field to its PHP type (declaration + phpdoc + default), per the
 * phase-87 type map: scalars, repeated → `list<T>`, map → `array<K,V>`,
 * enum → int-backed enum, and the well-known types (Timestamp/Duration/FieldMask
 * → string, wrappers → nullable scalar, Struct/Value/ListValue → array/mixed/list,
 * Any → array). bytes stay a raw PHP string (the hydrator base64-decodes).
 */
final class TypeMapper
{
    /**
     * Resolver for a message/enum type reference (phase 90): `(string $fqn):
     * string` returns the PHP name to write in a signature — a short class name
     * when the type is in the same namespace as the class being rendered, or a
     * leading-`\` FQN when it lives in another package's namespace. Set per
     * {@see forField} call. Null falls back to the flat short name (BC).
     *
     * @var (callable(string): string)|null
     */
    private $refFor = null;

    public function __construct(private readonly Symbols $symbols) {}

    /**
     * @param (callable(string): string)|null $refFor phase-90 cross-package type
     *        reference resolver (see {@see $refFor}); null keeps flat short names.
     */
    public function forField(FieldDescriptor $field, ?callable $refFor = null): PhpType
    {
        $this->refFor = $refFor;


        if ($field->isRepeated()) {
            // map<K,V> is a repeated synthetic entry message.
            if ($field->type === ProtoType::TYPE_MESSAGE && $this->symbols->isMapEntry($field->typeName)) {
                return $this->mapType($field);
            }
            $element = $this->element($field);
            return new PhpType('array', "list<{$element['doc']}>", '[]');
        }

        $element = $this->element($field);
        $nullable = $field->oneofIndex !== null || $field->type === ProtoType::TYPE_MESSAGE;

        if (!$nullable) {
            // Plain proto3 singular scalar/enum: non-null with a zero default.
            $default = $field->type === ProtoType::TYPE_ENUM
                ? $this->enumZeroDefault($field)
                : $this->scalarZeroDefault($field->type);
            return new PhpType($element['php'], $element['doc'] === $element['php'] ? null : $element['doc'], $default);
        }

        // Nullable: message presence, oneof member, or proto3 `optional` scalar.
        if ($element['php'] === 'mixed') {
            return new PhpType('mixed', null, 'null');
        }
        $doc = $element['doc'] === $element['php'] ? null : "{$element['doc']}|null";
        return new PhpType('?' . $element['php'], $doc, 'null');
    }

    /**
     * Singular, non-null element typing of a field: `['php' => decl, 'doc' => doc]`.
     *
     * @return array{php: string, doc: string}
     */
    private function element(FieldDescriptor $field): array
    {
        return match ($field->type) {
            ProtoType::TYPE_MESSAGE => $this->messageElement($field->typeName),
            ProtoType::TYPE_ENUM => $this->enumElement($field->typeName),
            default => $this->scalarElement($field->type),
        };
    }

    /**
     * The PHP reference for a message/enum type: short name (same namespace) or a
     * leading-`\` FQN (another package's namespace), via the phase-90 resolver.
     * Falls back to the flat short name when no resolver is set (BC).
     */
    private function ref(string $typeName): string
    {
        if ($this->refFor !== null) {
            return ($this->refFor)($typeName);
        }
        return $this->symbols->className($typeName) ?? '';
    }

    /**
     * @return array{php: string, doc: string}
     */
    private function messageElement(string $typeName): array
    {
        $wellKnown = self::WELL_KNOWN[$typeName] ?? null;
        if ($wellKnown !== null) {
            return $wellKnown;
        }
        if ($this->symbols->className($typeName) === null) {
            // Unknown message type (not in the pool) — fall back to a loose array.
            return ['php' => 'array', 'doc' => 'array<string, mixed>'];
        }
        $ref = $this->ref($typeName);
        return ['php' => $ref, 'doc' => $ref];
    }

    /**
     * @return array{php: string, doc: string}
     */
    private function enumElement(string $typeName): array
    {
        if ($this->symbols->className($typeName) === null) {
            return ['php' => 'int', 'doc' => 'int'];
        }
        $ref = $this->ref($typeName);
        return ['php' => $ref, 'doc' => $ref];
    }

    /**
     * @return array{php: string, doc: string}
     */
    private function scalarElement(int $type): array
    {
        $php = match ($type) {
            ProtoType::TYPE_DOUBLE, ProtoType::TYPE_FLOAT => 'float',
            ProtoType::TYPE_BOOL => 'bool',
            ProtoType::TYPE_STRING, ProtoType::TYPE_BYTES => 'string',
            default => 'int', // all integer families
        };
        return ['php' => $php, 'doc' => $php];
    }

    private function scalarZeroDefault(int $type): string
    {
        return match ($type) {
            ProtoType::TYPE_DOUBLE, ProtoType::TYPE_FLOAT => '0.0',
            ProtoType::TYPE_BOOL => 'false',
            ProtoType::TYPE_STRING, ProtoType::TYPE_BYTES => "''",
            default => '0',
        };
    }

    private function enumZeroDefault(FieldDescriptor $field): string
    {
        $enum = $this->symbols->enum($field->typeName);
        if ($enum === null || $this->symbols->className($field->typeName) === null) {
            return '0';
        }
        $zero = null;
        foreach ($enum->values as $value) {
            if ($value->number === 0) {
                $zero = $value->name;
                break;
            }
        }
        $zero ??= $enum->values[0]->name ?? null;
        // Cross-package enum default → leading-`\` FQN::Case (phase 90).
        $class = $this->ref($field->typeName);
        return $zero === null ? '0' : "{$class}::{$zero}";
    }

    private function mapType(FieldDescriptor $field): PhpType
    {
        $entry = $this->symbols->message($field->typeName);
        $keyDoc = 'string';
        $valDoc = 'mixed';
        if ($entry !== null) {
            foreach ($entry->fields as $f) {
                if ($f->number === 1) {
                    $keyDoc = $this->element($f)['doc'];
                } elseif ($f->number === 2) {
                    $valDoc = $this->element($f)['doc'];
                }
            }
        }
        // proto map keys are always integral or string.
        return new PhpType('array', "array<{$keyDoc}, {$valDoc}>", '[]');
    }

    /**
     * Well-known type → singular element typing. Keys are the leading-dot FQNs as
     * they appear in `FieldDescriptorProto.type_name`.
     *
     * @var array<string, array{php: string, doc: string}>
     */
    private const WELL_KNOWN = [
        '.google.protobuf.Timestamp' => ['php' => 'string', 'doc' => 'string'],
        '.google.protobuf.Duration' => ['php' => 'string', 'doc' => 'string'],
        '.google.protobuf.FieldMask' => ['php' => 'string', 'doc' => 'string'],
        '.google.protobuf.DoubleValue' => ['php' => 'float', 'doc' => 'float'],
        '.google.protobuf.FloatValue' => ['php' => 'float', 'doc' => 'float'],
        '.google.protobuf.Int64Value' => ['php' => 'int', 'doc' => 'int'],
        '.google.protobuf.UInt64Value' => ['php' => 'int', 'doc' => 'int'],
        '.google.protobuf.Int32Value' => ['php' => 'int', 'doc' => 'int'],
        '.google.protobuf.UInt32Value' => ['php' => 'int', 'doc' => 'int'],
        '.google.protobuf.BoolValue' => ['php' => 'bool', 'doc' => 'bool'],
        '.google.protobuf.StringValue' => ['php' => 'string', 'doc' => 'string'],
        '.google.protobuf.BytesValue' => ['php' => 'string', 'doc' => 'string'],
        '.google.protobuf.Struct' => ['php' => 'array', 'doc' => 'array<string, mixed>'],
        '.google.protobuf.Value' => ['php' => 'mixed', 'doc' => 'mixed'],
        '.google.protobuf.ListValue' => ['php' => 'array', 'doc' => 'list<mixed>'],
        '.google.protobuf.Any' => ['php' => 'array', 'doc' => 'array<string, mixed>'],
        '.google.protobuf.Empty' => ['php' => 'array', 'doc' => 'array<string, mixed>'],
    ];
}
