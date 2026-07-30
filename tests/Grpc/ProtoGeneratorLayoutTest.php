<?php

declare(strict_types=1);

namespace Folk\Sdk\Tests\Grpc;

use Folk\Sdk\Grpc\Codegen\Descriptor\FieldDescriptor;
use Folk\Sdk\Grpc\Codegen\Descriptor\FileDescriptor;
use Folk\Sdk\Grpc\Codegen\Descriptor\MessageDescriptor;
use Folk\Sdk\Grpc\Codegen\Descriptor\MethodDescriptor;
use Folk\Sdk\Grpc\Codegen\Descriptor\ServiceDescriptor;
use Folk\Sdk\Grpc\Codegen\ProtoGenerator;
use Folk\Sdk\Grpc\Codegen\ProtoType;
use PHPUnit\Framework\TestCase;

/**
 * Phase 90 — package-based codegen layout: `{package}` sub-namespaces, cross-package
 * FQN references, same-package short names, and package-mirrored output paths.
 * Mirrors the real globalst shape (io.altessa.serviceinfo.v1 → io.altessa.type.v1).
 */
final class ProtoGeneratorLayoutTest extends TestCase
{
    private const NS = 'App\\Grpc\\Generated\\Server\\{package}';

    /** @return list<FileDescriptor> */
    private function files(): array
    {
        $string = static fn (string $name, int $n): FieldDescriptor => new FieldDescriptor(
            $name, $n, ProtoType::LABEL_OPTIONAL, ProtoType::TYPE_STRING, '', null, false,
        );
        $message = static fn (string $name, int $n, string $type): FieldDescriptor => new FieldDescriptor(
            $name, $n, ProtoType::LABEL_OPTIONAL, ProtoType::TYPE_MESSAGE, $type, null, false,
        );

        // package io.altessa.type.v1: FileRef { string uri = 1; }
        $typeFile = new FileDescriptor('type.proto', 'io.altessa.type.v1', 'proto3', [
            new MessageDescriptor('FileRef', [$string('uri', 1)], [], [], []),
        ], [], []);

        // package io.altessa.serviceinfo.v1: Meta, ServiceInfo (refs FileRef cross-pkg,
        // Meta same-pkg), and a service Catalog(ServiceInfo) → FileRef (cross-pkg out).
        $serviceFile = new FileDescriptor('serviceinfo.proto', 'io.altessa.serviceinfo.v1', 'proto3', [
            new MessageDescriptor('Meta', [$string('name', 1)], [], [], []),
            new MessageDescriptor('ServiceInfo', [
                $message('file', 1, '.io.altessa.type.v1.FileRef'),        // cross-package
                $message('meta', 2, '.io.altessa.serviceinfo.v1.Meta'),    // same-package
            ], [], [], []),
        ], [], [
            new ServiceDescriptor('Catalog', [
                new MethodDescriptor(
                    'Get',
                    '.io.altessa.serviceinfo.v1.ServiceInfo',   // in — same pkg as service
                    '.io.altessa.type.v1.FileRef',              // out — cross-package
                    false,
                    false,
                ),
            ]),
        ]);

        return [$typeFile, $serviceFile];
    }

    /** @return array<string, string> */
    private function generate(string $ns): array
    {
        return (new ProtoGenerator($this->files(), $ns, ProtoGenerator::ROLE_SERVER))->generate();
    }

    public function testPathsMirrorPackage(): void
    {
        $out = $this->generate(self::NS);

        self::assertArrayHasKey('Io/Altessa/Type/V1/FileRef.php', $out);
        self::assertArrayHasKey('Io/Altessa/Serviceinfo/V1/Meta.php', $out);
        self::assertArrayHasKey('Io/Altessa/Serviceinfo/V1/ServiceInfo.php', $out);
        self::assertArrayHasKey('Io/Altessa/Serviceinfo/V1/CatalogInterface.php', $out);
    }

    public function testPerPackageNamespaceAndCrossPackageFqn(): void
    {
        $src = $this->generate(self::NS)['Io/Altessa/Serviceinfo/V1/ServiceInfo.php'];

        self::assertStringContainsString(
            'namespace App\\Grpc\\Generated\\Server\\Io\\Altessa\\Serviceinfo\\V1;',
            $src,
        );
        // Cross-package field → leading-\ FQN; same-package field → short name.
        self::assertStringContainsString(
            'public ?\\App\\Grpc\\Generated\\Server\\Io\\Altessa\\Type\\V1\\FileRef $file = null,',
            $src,
        );
        self::assertStringContainsString('public ?Meta $meta = null,', $src);
        // FOLK_FIELDS ::class references follow the same rule (Hydrator needs real FQNs).
        self::assertStringContainsString(
            "'file' => ['m', \\App\\Grpc\\Generated\\Server\\Io\\Altessa\\Type\\V1\\FileRef::class]",
            $src,
        );
        self::assertStringContainsString("'meta' => ['m', Meta::class]", $src);
    }

    public function testInterfaceUsesCrossPackageFqnForOut(): void
    {
        $src = $this->generate(self::NS)['Io/Altessa/Serviceinfo/V1/CatalogInterface.php'];

        // in (same pkg) short, out (cross-pkg) FQN.
        self::assertStringContainsString(
            'public function Get(ServiceInfo $request, Context $context): ?\\App\\Grpc\\Generated\\Server\\Io\\Altessa\\Type\\V1\\FileRef;',
            $src,
        );
    }

    public function testGeneratedTreeIsSyntacticallyValidAndLoads(): void
    {
        $out = $this->generate('Folk\\Sdk\\Tests\\GenLayout\\Server\\{package}');

        $dir = sys_get_temp_dir() . '/folk-gen-layout-' . getmypid();
        foreach ($out as $rel => $source) {
            $path = $dir . '/' . $rel;
            @mkdir(dirname($path), 0o777, true);
            file_put_contents($path, $source);
            $lint = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1');
            self::assertIsString($lint);
            self::assertStringContainsString('No syntax errors', $lint, "syntax error in {$rel}: {$lint}");
            require_once $path;
        }

        self::assertTrue(class_exists('Folk\\Sdk\\Tests\\GenLayout\\Server\\Io\\Altessa\\Serviceinfo\\V1\\ServiceInfo'));
        self::assertTrue(class_exists('Folk\\Sdk\\Tests\\GenLayout\\Server\\Io\\Altessa\\Type\\V1\\FileRef'));
    }

    public function testHydratorRoundTripsCrossPackageDtos(): void
    {
        // The runtime proof: generated package-layout DTOs load under PSR-4 and the
        // Hydrator round-trips a cross-package nested message via its `::class` FQN
        // (transcoding is unchanged — only the code layout moved).
        $out = $this->generate('Folk\\Sdk\\Tests\\GenRoundtrip\\Server\\{package}');

        $dir = sys_get_temp_dir() . '/folk-gen-rt-' . getmypid();
        foreach ($out as $rel => $source) {
            $path = $dir . '/' . $rel;
            @mkdir(dirname($path), 0o777, true);
            file_put_contents($path, $source);
            require_once $path;
        }

        $serviceInfo = 'Folk\\Sdk\\Tests\\GenRoundtrip\\Server\\Io\\Altessa\\Serviceinfo\\V1\\ServiceInfo';
        $fileRef = 'Folk\\Sdk\\Tests\\GenRoundtrip\\Server\\Io\\Altessa\\Type\\V1\\FileRef';

        $hydrator = new \Folk\Sdk\Grpc\Hydrator();
        $dto = $hydrator->hydrate($serviceInfo, ['file' => ['uri' => 'gs://x'], 'meta' => ['name' => 'svc']]);

        self::assertInstanceOf($serviceInfo, $dto);
        self::assertInstanceOf($fileRef, $dto->file, 'cross-package nested message hydrated via FQN ::class');
        self::assertSame('gs://x', $dto->file->uri);
        self::assertSame('svc', $dto->meta->name);

        self::assertSame(
            ['file' => ['uri' => 'gs://x'], 'meta' => ['name' => 'svc']],
            $hydrator->dehydrate($dto),
            'dehydrate round-trips',
        );
    }

    public function testFlatModeUnchangedWithoutPackagePlaceholder(): void
    {
        // No {package} → legacy flat layout (BC): flat paths, short refs.
        $out = $this->generate('App\\Grpc\\Flat');

        self::assertArrayHasKey('ServiceInfo.php', $out);
        self::assertArrayHasKey('FileRef.php', $out);
        $src = $out['ServiceInfo.php'];
        self::assertStringContainsString('namespace App\\Grpc\\Flat;', $src);
        self::assertStringContainsString('public ?FileRef $file = null,', $src, 'flat: short ref, no FQN');
        self::assertStringNotContainsString('\\App\\Grpc\\Flat\\FileRef', $src);
    }
}
