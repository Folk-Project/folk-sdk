<?php declare(strict_types=1);

use Folk\Sdk\Grpc\Codegen\Descriptor\FieldDescriptor;
use Folk\Sdk\Grpc\Codegen\Descriptor\FileDescriptor;
use Folk\Sdk\Grpc\Codegen\Descriptor\MessageDescriptor;
use Folk\Sdk\Grpc\Codegen\ProtoGenerator;
use Folk\Sdk\Grpc\Codegen\ProtoType;
use PHPUnit\Framework\TestCase;

/**
 * PHP method names are case-insensitive, so two distinct proto fields whose
 * PascalCase accessor suffixes differ only in case (`created_at` → `getCreatedAt`,
 * `createdat` → `getCreatedat`) would emit two methods PHP treats as one → a fatal
 * "cannot redeclare" in the generated DTO. {@see ProtoGenerator::renderMessage}
 * must reject that at generation time with a clear error naming both fields, not
 * ship a broken class. Pathological naming (google-protobuf has the same clash);
 * no current fixture/stand hits it — hence the hand-built descriptor here.
 */
final class ProtoGeneratorCollisionTest extends TestCase
{
    private const NS = 'Folk\\Sdk\\Tests\\Collision';

    /** @return list<FileDescriptor> */
    private function fileWithFields(string $messageName, string ...$fieldNames): array
    {
        $fields = [];
        $number = 1;
        foreach ($fieldNames as $fieldName) {
            $fields[] = new FieldDescriptor(
                name: $fieldName,
                number: $number++,
                label: ProtoType::LABEL_OPTIONAL,
                type: ProtoType::TYPE_STRING,
                typeName: '',
                oneofIndex: null,
                proto3Optional: false,
            );
        }

        return [new FileDescriptor(
            name: 'collision.proto',
            package: 'folk.test.collision',
            syntax: 'proto3',
            messages: [new MessageDescriptor(
                name: $messageName,
                fields: $fields,
                oneofs: [],
                nestedMessages: [],
                nestedEnums: [],
            )],
            enums: [],
            services: [],
        )];
    }

    public function testCaseInsensitiveAccessorCollisionThrows(): void
    {
        $files = $this->fileWithFields('Clash', 'created_at', 'createdat');

        $this->expectException(\RuntimeException::class);
        // The error names both offending fields and the shared accessor.
        $this->expectExceptionMessageMatches('/created_at.*createdat|createdat.*created_at/');
        $this->expectExceptionMessageMatches('/getCreatedat|getCreatedAt/');

        (new ProtoGenerator($files, self::NS))->generate();
    }

    public function testDistinctAccessorsDoNotThrow(): void
    {
        // Sanity: fields that map to genuinely distinct accessors still generate.
        $files = $this->fileWithFields('Fine', 'created_at', 'updated_at');

        $out = (new ProtoGenerator($files, self::NS))->generate();

        $this->assertArrayHasKey('Fine.php', $out);
        $src = $out['Fine.php'];
        $this->assertStringContainsString('public function getCreatedAt(', $src);
        $this->assertStringContainsString('public function getUpdatedAt(', $src);
    }
}
