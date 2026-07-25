<?php

declare(strict_types=1);

namespace Folk\Sdk\Grpc\Codegen;

use Folk\Sdk\Grpc\Codegen\Descriptor\EnumDescriptor;
use Folk\Sdk\Grpc\Codegen\Descriptor\EnumValueDescriptor;
use Folk\Sdk\Grpc\Codegen\Descriptor\FieldDescriptor;
use Folk\Sdk\Grpc\Codegen\Descriptor\FileDescriptor;
use Folk\Sdk\Grpc\Codegen\Descriptor\MessageDescriptor;
use Folk\Sdk\Grpc\Codegen\Descriptor\MethodDescriptor;
use Folk\Sdk\Grpc\Codegen\Descriptor\ServiceDescriptor;

/**
 * Parse an encoded `FileDescriptorSet` (protobuf bytes, as produced by protox in
 * the Rust plugin or by `protoc --descriptor_set_out`) into a descriptor tree —
 * in pure PHP, no protoc, no protobuf extension.
 *
 * Only the descriptor.proto fields the generator needs are decoded; the rest is
 * skipped. Field numbers below are from descriptor.proto.
 */
final class FdsParser
{
    /**
     * @return list<FileDescriptor>
     */
    public static function parse(string $fds): array
    {
        $reader = new ProtoReader($fds);
        $files = [];
        while (!$reader->eof()) {
            [$field, $wire] = $reader->readTag();
            if ($field === 1 && $wire === 2) { // FileDescriptorSet.file
                $files[] = self::parseFile($reader->readLengthDelimited());
            } else {
                $reader->skip($wire);
            }
        }
        return $files;
    }

    private static function parseFile(string $buf): FileDescriptor
    {
        $reader = new ProtoReader($buf);
        $name = '';
        $package = '';
        $syntax = 'proto2';
        $messages = [];
        $enums = [];
        $services = [];
        while (!$reader->eof()) {
            [$field, $wire] = $reader->readTag();
            switch ($field) {
                case 1: // name
                    $name = $reader->readLengthDelimited();
                    break;
                case 2: // package
                    $package = $reader->readLengthDelimited();
                    break;
                case 4: // message_type (DescriptorProto)
                    $messages[] = self::parseMessage($reader->readLengthDelimited());
                    break;
                case 5: // enum_type (EnumDescriptorProto)
                    $enums[] = self::parseEnum($reader->readLengthDelimited());
                    break;
                case 6: // service (ServiceDescriptorProto)
                    $services[] = self::parseService($reader->readLengthDelimited());
                    break;
                case 12: // syntax
                    $syntax = $reader->readLengthDelimited();
                    break;
                default:
                    $reader->skip($wire);
            }
        }
        return new FileDescriptor($name, $package, $syntax, $messages, $enums, $services);
    }

    private static function parseMessage(string $buf): MessageDescriptor
    {
        $reader = new ProtoReader($buf);
        $name = '';
        $fields = [];
        $oneofs = [];
        $nestedMessages = [];
        $nestedEnums = [];
        $isMapEntry = false;
        while (!$reader->eof()) {
            [$field, $wire] = $reader->readTag();
            switch ($field) {
                case 1: // name
                    $name = $reader->readLengthDelimited();
                    break;
                case 2: // field (FieldDescriptorProto)
                    $fields[] = self::parseField($reader->readLengthDelimited());
                    break;
                case 3: // nested_type (DescriptorProto)
                    $nestedMessages[] = self::parseMessage($reader->readLengthDelimited());
                    break;
                case 4: // enum_type (EnumDescriptorProto)
                    $nestedEnums[] = self::parseEnum($reader->readLengthDelimited());
                    break;
                case 7: // options (MessageOptions) — only map_entry is needed
                    $isMapEntry = self::parseMapEntryOption($reader->readLengthDelimited());
                    break;
                case 8: // oneof_decl (OneofDescriptorProto) — name only
                    $oneofs[] = self::parseOneofName($reader->readLengthDelimited());
                    break;
                default:
                    $reader->skip($wire);
            }
        }
        return new MessageDescriptor($name, $fields, $oneofs, $nestedMessages, $nestedEnums, $isMapEntry);
    }

    private static function parseField(string $buf): FieldDescriptor
    {
        $reader = new ProtoReader($buf);
        $name = '';
        $number = 0;
        $label = 0;
        $type = 0;
        $typeName = '';
        $oneofIndex = null;
        $proto3Optional = false;
        while (!$reader->eof()) {
            [$field, $wire] = $reader->readTag();
            switch ($field) {
                case 1: // name
                    $name = $reader->readLengthDelimited();
                    break;
                case 3: // number
                    $number = $reader->readVarint();
                    break;
                case 4: // label
                    $label = $reader->readVarint();
                    break;
                case 5: // type
                    $type = $reader->readVarint();
                    break;
                case 6: // type_name
                    $typeName = $reader->readLengthDelimited();
                    break;
                case 9: // oneof_index
                    $oneofIndex = $reader->readVarint();
                    break;
                case 17: // proto3_optional
                    $proto3Optional = $reader->readVarint() !== 0;
                    break;
                default:
                    $reader->skip($wire);
            }
        }
        return new FieldDescriptor($name, $number, $label, $type, $typeName, $oneofIndex, $proto3Optional);
    }

    private static function parseEnum(string $buf): EnumDescriptor
    {
        $reader = new ProtoReader($buf);
        $name = '';
        $values = [];
        while (!$reader->eof()) {
            [$field, $wire] = $reader->readTag();
            switch ($field) {
                case 1: // name
                    $name = $reader->readLengthDelimited();
                    break;
                case 2: // value (EnumValueDescriptorProto)
                    $values[] = self::parseEnumValue($reader->readLengthDelimited());
                    break;
                default:
                    $reader->skip($wire);
            }
        }
        return new EnumDescriptor($name, $values);
    }

    private static function parseEnumValue(string $buf): EnumValueDescriptor
    {
        $reader = new ProtoReader($buf);
        $name = '';
        $number = 0;
        while (!$reader->eof()) {
            [$field, $wire] = $reader->readTag();
            switch ($field) {
                case 1: // name
                    $name = $reader->readLengthDelimited();
                    break;
                case 2: // number
                    $number = $reader->readVarint();
                    break;
                default:
                    $reader->skip($wire);
            }
        }
        return new EnumValueDescriptor($name, $number);
    }

    private static function parseService(string $buf): ServiceDescriptor
    {
        $reader = new ProtoReader($buf);
        $name = '';
        $methods = [];
        while (!$reader->eof()) {
            [$field, $wire] = $reader->readTag();
            switch ($field) {
                case 1: // name
                    $name = $reader->readLengthDelimited();
                    break;
                case 2: // method (MethodDescriptorProto)
                    $methods[] = self::parseMethod($reader->readLengthDelimited());
                    break;
                default:
                    $reader->skip($wire);
            }
        }
        return new ServiceDescriptor($name, $methods);
    }

    private static function parseMethod(string $buf): MethodDescriptor
    {
        $reader = new ProtoReader($buf);
        $name = '';
        $inputType = '';
        $outputType = '';
        $clientStreaming = false;
        $serverStreaming = false;
        while (!$reader->eof()) {
            [$field, $wire] = $reader->readTag();
            switch ($field) {
                case 1: // name
                    $name = $reader->readLengthDelimited();
                    break;
                case 2: // input_type
                    $inputType = $reader->readLengthDelimited();
                    break;
                case 3: // output_type
                    $outputType = $reader->readLengthDelimited();
                    break;
                case 5: // client_streaming
                    $clientStreaming = $reader->readVarint() !== 0;
                    break;
                case 6: // server_streaming
                    $serverStreaming = $reader->readVarint() !== 0;
                    break;
                default:
                    $reader->skip($wire);
            }
        }
        return new MethodDescriptor($name, $inputType, $outputType, $clientStreaming, $serverStreaming);
    }

    /** Read `MessageOptions.map_entry` (field 7) — marks a synthetic map entry. */
    private static function parseMapEntryOption(string $buf): bool
    {
        $reader = new ProtoReader($buf);
        $mapEntry = false;
        while (!$reader->eof()) {
            [$field, $wire] = $reader->readTag();
            if ($field === 7 && $wire === 0) {
                $mapEntry = $reader->readVarint() !== 0;
            } else {
                $reader->skip($wire);
            }
        }
        return $mapEntry;
    }

    private static function parseOneofName(string $buf): string
    {
        $reader = new ProtoReader($buf);
        $name = '';
        while (!$reader->eof()) {
            [$field, $wire] = $reader->readTag();
            if ($field === 1 && $wire === 2) { // name
                $name = $reader->readLengthDelimited();
            } else {
                $reader->skip($wire);
            }
        }
        return $name;
    }
}
