<?php

declare(strict_types=1);

namespace Folk\Sdk\Tests\Grpc;

use Folk\Sdk\Grpc\Codegen\Descriptor\FileDescriptor;
use Folk\Sdk\Grpc\Codegen\Descriptor\MessageDescriptor;
use Folk\Sdk\Grpc\Codegen\Symbols;
use PHPUnit\Framework\TestCase;

/**
 * Phase 90 — the package/namespace resolution the codegen layout is built on.
 */
final class SymbolsTest extends TestCase
{
    public function testPackageToNsStudlyCasesSegments(): void
    {
        self::assertSame('Io\\Altessa\\Serviceinfo\\V1', Symbols::packageToNs('io.altessa.serviceinfo.v1'));
        self::assertSame('Google\\Type', Symbols::packageToNs('google.type'));
        self::assertSame('', Symbols::packageToNs(''), 'empty package → empty namespace');
        self::assertSame('Foo', Symbols::packageToNs('foo'));
    }

    public function testPackageOfTopLevelAndNested(): void
    {
        $inner = new MessageDescriptor('Inner', [], [], [], []);
        $outer = new MessageDescriptor('ServiceInfo', [], [], [$inner], []);
        $file = new FileDescriptor(
            'serviceinfo.proto',
            'io.altessa.serviceinfo.v1',
            'proto3',
            [$outer],
            [],
            [],
        );

        $symbols = new Symbols([$file]);

        // Top-level and nested both report the file's proto package.
        self::assertSame(
            'io.altessa.serviceinfo.v1',
            $symbols->package('.io.altessa.serviceinfo.v1.ServiceInfo'),
        );
        self::assertSame(
            'io.altessa.serviceinfo.v1',
            $symbols->package('.io.altessa.serviceinfo.v1.ServiceInfo.Inner'),
            'nested type keeps its file package',
        );

        // The nested class name is flattened (existing behaviour, unchanged).
        self::assertSame(
            'ServiceInfoInner',
            $symbols->className('.io.altessa.serviceinfo.v1.ServiceInfo.Inner'),
        );
    }

    public function testUnknownFqnAndUnnamedPackage(): void
    {
        $msg = new MessageDescriptor('Bare', [], [], [], []);
        $file = new FileDescriptor('bare.proto', '', 'proto3', [$msg], [], []);
        $symbols = new Symbols([$file]);

        self::assertSame('', $symbols->package('.Bare'), 'unnamed package → empty');
        self::assertSame('', $symbols->package('.does.not.Exist'), 'unknown fqn → empty (safe default)');
    }
}
