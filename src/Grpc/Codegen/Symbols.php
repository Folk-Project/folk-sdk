<?php

declare(strict_types=1);

namespace Folk\Sdk\Grpc\Codegen;

use Folk\Sdk\Grpc\Codegen\Descriptor\EnumDescriptor;
use Folk\Sdk\Grpc\Codegen\Descriptor\FileDescriptor;
use Folk\Sdk\Grpc\Codegen\Descriptor\MessageDescriptor;

/**
 * Symbol table for a `FileDescriptorSet`: resolves a fully-qualified proto name
 * (e.g. `.pkg.Msg.Nested`) to its descriptor and to the flat PHP class name the
 * generator emits (nested names are joined, e.g. `Msg` + `Nested` → `MsgNested`).
 */
final class Symbols
{
    /** @var array<string, MessageDescriptor> fqn (leading dot) → message */
    private array $messages = [];

    /** @var array<string, EnumDescriptor> fqn (leading dot) → enum */
    private array $enums = [];

    /** @var array<string, string> fqn (leading dot) → flat PHP class short name */
    private array $classNames = [];

    /**
     * @param list<FileDescriptor> $files
     */
    public function __construct(array $files)
    {
        foreach ($files as $file) {
            $prefix = $file->package === '' ? '' : '.' . $file->package;
            foreach ($file->enums as $enum) {
                $this->registerEnum($prefix, '', $enum);
            }
            foreach ($file->messages as $message) {
                $this->registerMessage($prefix, '', $message);
            }
        }
    }

    private function registerEnum(string $scope, string $classPrefix, EnumDescriptor $enum): void
    {
        $fqn = $scope . '.' . $enum->name;
        $this->enums[$fqn] = $enum;
        $this->classNames[$fqn] = $classPrefix . $enum->name;
    }

    private function registerMessage(string $scope, string $classPrefix, MessageDescriptor $message): void
    {
        $fqn = $scope . '.' . $message->name;
        $className = $classPrefix . $message->name;
        $this->messages[$fqn] = $message;
        $this->classNames[$fqn] = $className;

        foreach ($message->nestedEnums as $enum) {
            $this->registerEnum($fqn, $className, $enum);
        }
        foreach ($message->nestedMessages as $nested) {
            $this->registerMessage($fqn, $className, $nested);
        }
    }

    public function message(string $fqn): ?MessageDescriptor
    {
        return $this->messages[$fqn] ?? null;
    }

    public function enum(string $fqn): ?EnumDescriptor
    {
        return $this->enums[$fqn] ?? null;
    }

    public function className(string $fqn): ?string
    {
        return $this->classNames[$fqn] ?? null;
    }

    /** True when the FQN is a synthetic map-entry message. */
    public function isMapEntry(string $fqn): bool
    {
        $message = $this->messages[$fqn] ?? null;
        return $message !== null && $message->isMapEntry;
    }

    /**
     * Generatable messages (map entries and well-known `google.protobuf.*`
     * excluded — the latter are mapped by {@see TypeMapper}, not emitted).
     *
     * @return array<string, MessageDescriptor>
     */
    public function generatableMessages(): array
    {
        return array_filter(
            $this->messages,
            fn (MessageDescriptor $m, string $fqn): bool => !$m->isMapEntry && $this->isGeneratable($fqn),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Generatable enums (well-known package excluded).
     *
     * @return array<string, EnumDescriptor>
     */
    public function generatableEnums(): array
    {
        return array_filter(
            $this->enums,
            fn (EnumDescriptor $e, string $fqn): bool => $this->isGeneratable($fqn),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /** Whether a type should be emitted (skip the well-known protobuf package). */
    private function isGeneratable(string $fqn): bool
    {
        return !str_starts_with($fqn, '.google.protobuf.');
    }
}
