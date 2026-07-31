<?php

declare(strict_types=1);

namespace Folk\Sdk\Tests\Grpc;

use Folk\Sdk\Grpc\Codegen\Descriptor\FileDescriptor;
use Folk\Sdk\Grpc\Codegen\Descriptor\MessageDescriptor;
use Folk\Sdk\Grpc\Codegen\Descriptor\MethodDescriptor;
use Folk\Sdk\Grpc\Codegen\Descriptor\ServiceDescriptor;
use Folk\Sdk\Grpc\Codegen\ProtoGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Phase 88b §5 — client-stub and server-interface codegen for streaming RPCs.
 * Drives {@see ProtoGenerator} on an in-memory descriptor set with one method of
 * each kind (unary / server- / client- / bidi-streaming) and asserts the emitted
 * signatures.
 */
final class ProtoGeneratorStreamTest extends TestCase
{
    /** @return list<FileDescriptor> */
    private function streamFiles(): array
    {
        $msg = static fn (string $n): MessageDescriptor => new MessageDescriptor($n, [], [], [], []);
        $m = static fn (string $name, bool $c, bool $s): MethodDescriptor => new MethodDescriptor(
            $name,
            '.folk.test.stream.Req',
            '.folk.test.stream.Resp',
            $c,
            $s,
        );

        $service = new ServiceDescriptor('Watcher', [
            $m('Get', false, false),    // unary
            $m('Watch', false, true),   // server-streaming
            $m('Upload', true, false),  // client-streaming
            $m('Chat', true, true),     // bidi
        ]);

        return [new FileDescriptor(
            'stream.proto',
            'folk.test.stream',
            'proto3',
            [$msg('Req'), $msg('Resp')],
            [],
            [$service],
        )];
    }

    public function testClientStubEmitsStreamingSignatures(): void
    {
        $out = (new ProtoGenerator($this->streamFiles(), 'App\\Gen', ProtoGenerator::ROLE_CLIENT, 'watcher'))
            ->generate();

        self::assertArrayHasKey('WatcherClient.php', $out);
        $src = $out['WatcherClient.php'];

        // A service that mixes unary + streaming extends the streaming base.
        self::assertStringContainsString('use Folk\\Sdk\\Grpc\\Client\\GrpcStreamClient;', $src);
        self::assertStringContainsString('final class WatcherClient extends GrpcStreamClient', $src);

        // Unary — unchanged phase-88 shape.
        self::assertStringContainsString('public function Get(Req $request): Resp', $src);
        self::assertStringContainsString("return \$this->call('Get', \$request, Resp::class);", $src);

        // Server-streaming: one request → Generator<int, Resp>.
        self::assertStringContainsString('public function Watch(Req $request): \\Generator', $src);
        self::assertStringContainsString("return \$this->serverStream('Watch', \$request, Resp::class);", $src);

        // Client-streaming: iterable<Req> → Resp.
        self::assertStringContainsString('public function Upload(iterable $requests): Resp', $src);
        self::assertStringContainsString("return \$this->clientStream('Upload', \$requests, Resp::class);", $src);

        // Bidi: iterable<Req> → Generator<int, Resp>.
        self::assertStringContainsString('public function Chat(iterable $requests): \\Generator', $src);
        self::assertStringContainsString("return \$this->bidiStream('Chat', \$requests, Resp::class);", $src);
    }

    public function testUnaryOnlyServiceKeepsLeanBase(): void
    {
        $service = new ServiceDescriptor('Pinger', [
            new MethodDescriptor('Ping', '.folk.test.stream.Req', '.folk.test.stream.Resp', false, false),
        ]);
        $files = [new FileDescriptor(
            'ping.proto',
            'folk.test.stream',
            'proto3',
            [new MessageDescriptor('Req', [], [], [], []), new MessageDescriptor('Resp', [], [], [], [])],
            [],
            [$service],
        )];

        $src = (new ProtoGenerator($files, 'App\\Gen', ProtoGenerator::ROLE_CLIENT, 'p'))
            ->generate()['PingerClient.php'];

        // No streaming methods ⇒ the phase-88 base is preserved (no churn).
        self::assertStringContainsString('extends GrpcClient', $src);
        self::assertStringNotContainsString('GrpcStreamClient', $src);
    }

    public function testServerInterfaceEmitsAllFourMethodKinds(): void
    {
        $out = (new ProtoGenerator($this->streamFiles(), 'App\\Gen', ProtoGenerator::ROLE_SERVER, ''))
            ->generate();

        self::assertArrayHasKey('WatcherInterface.php', $out);
        $src = $out['WatcherInterface.php'];

        // Unary.
        self::assertStringContainsString(
            'public function Get(Req $request, Context $context): ?Resp;',
            $src,
        );

        // Server-streaming: a handler that yields — iterable return.
        self::assertStringContainsString('/** @return iterable<Resp> */', $src);
        self::assertStringContainsString(
            'public function Watch(Req $request, Context $context): iterable;',
            $src,
        );

        // Client-streaming (phase 94, #92): a stream of requests, one response.
        self::assertStringContainsString('/** @param iterable<Req> $requests */', $src);
        self::assertStringContainsString(
            'public function Upload(iterable $requests, Context $context): ?Resp;',
            $src,
        );

        // Bidi (phase 94, #92): a stream of requests, a stream of responses.
        self::assertStringContainsString(
            '/** @param iterable<Req> $requests @return iterable<Resp> */',
            $src,
        );
        self::assertStringContainsString(
            'public function Chat(iterable $requests, Context $context): iterable;',
            $src,
        );

        // The inbound-stream element DTOs are exposed for the router to hydrate
        // (the `iterable` param carries no element type at runtime).
        self::assertStringContainsString(
            "public const INPUT_STREAMS = ['Upload' => Req::class, 'Chat' => Req::class];",
            $src,
        );
    }

    public function testGeneratedStreamStubIsSyntacticallyValidAndLoads(): void
    {
        $out = (new ProtoGenerator($this->streamFiles(), 'Folk\\Sdk\\Tests\\GenStream', ProtoGenerator::ROLE_CLIENT, 'watcher'))
            ->generate();

        $dir = sys_get_temp_dir() . '/folk-gen-stream-' . getmypid();
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        foreach ($out as $name => $source) {
            // `php -l` catches any heredoc-indentation slip in the generated code.
            $path = $dir . '/' . $name;
            file_put_contents($path, $source);
            $lint = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1');
            self::assertIsString($lint);
            self::assertStringContainsString('No syntax errors', $lint, "syntax error in generated {$name}: {$lint}");
            require_once $path;
        }

        $stub = 'Folk\\Sdk\\Tests\\GenStream\\WatcherClient';
        self::assertTrue(class_exists($stub));
        self::assertTrue(is_subclass_of($stub, \Folk\Sdk\Grpc\Client\GrpcStreamClient::class));
    }
}
