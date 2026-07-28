<?php declare(strict_types=1);

use Folk\Sdk\Grpc\Client\GrpcClient;
use Folk\Sdk\Grpc\GrpcException;
use Folk\Sdk\Tests\GrpcFixtures\Inner;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixtures/dto.php';

/**
 * Concrete client stub mirroring what {@see \Folk\Sdk\Grpc\Codegen\ProtoGenerator}
 * emits for the client role: baked CLIENT/SERVICE constants and typed unary methods
 * delegating to {@see GrpcClient::call()}.
 */
final class EchoTestClient extends GrpcClient
{
    public const CLIENT = 'greeter';
    public const SERVICE = 'folk.test.hello.Greeter';

    public function Echo(Inner $request): Inner
    {
        return $this->call('Echo', $request, Inner::class);
    }
}

/**
 * Phase 88 §2.3 — runtime facade tests. The RPC bridge is injected as a closure so
 * the full envelope→transport→response path is exercised without the extension.
 */
final class GrpcClientTest extends TestCase
{
    /** @var list<array{string, string}> captured (method, payload) bridge calls */
    private array $calls = [];

    /**
     * A transport that records the call and returns `$reply` (a JSON string).
     */
    private function transport(string $reply): callable
    {
        return function (string $method, string $payload) use ($reply): string {
            $this->calls[] = [$method, $payload];
            return $reply;
        };
    }

    /** @return array<string, mixed> the decoded envelope of the last bridge call */
    private function lastEnvelope(): array
    {
        $last = $this->calls[array_key_last($this->calls)];
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($last[1], true, 512, JSON_THROW_ON_ERROR);
        return $decoded;
    }

    public function testUnaryRoundTripDehydratesRequestAndHydratesResponse(): void
    {
        $client = new EchoTestClient(null, $this->transport('{"message":{"label":"pong"}}'));

        $resp = $client->Echo(new Inner('ping'));

        $this->assertInstanceOf(Inner::class, $resp);
        $this->assertSame('pong', $resp->label);

        $this->assertSame([['grpc.client.call', $this->calls[0][1]]], $this->calls);
        $env = $this->lastEnvelope();
        $this->assertSame('greeter', $env['client']);
        $this->assertSame('folk.test.hello.Greeter', $env['service']);
        $this->assertSame('Echo', $env['method']);
        $this->assertSame(['label' => 'ping'], $env['message']);
        $this->assertArrayNotHasKey('address', $env, 'no override → no address key');
    }

    public function testBusinessStatusThrowsGrpcException(): void
    {
        $client = new EchoTestClient(null, $this->transport('{"__grpc_status":5,"__grpc_message":"not found"}'));

        try {
            $client->Echo(new Inner('x'));
            $this->fail('expected GrpcException');
        } catch (GrpcException $e) {
            $this->assertSame(5, $e->status());
            $this->assertSame(5, $e->getCode());
            $this->assertSame('not found', $e->getMessage());
        }
    }

    public function testTransportUnavailableStatusThrows(): void
    {
        $client = new EchoTestClient(null, $this->transport('{"__grpc_status":14,"__grpc_message":"connect: refused"}'));

        $this->expectException(GrpcException::class);
        $this->expectExceptionCode(14);
        $client->Echo(new Inner('x'));
    }

    public function testAddressOverrideIncludedInEnvelope(): void
    {
        $client = new EchoTestClient('other:50051', $this->transport('{"message":{"label":"ok"}}'));
        $client->Echo(new Inner('x'));
        $this->assertSame('other:50051', $this->lastEnvelope()['address']);
    }

    public function testMetadataAndDeadlineAreImmutableAndSent(): void
    {
        $base = new EchoTestClient(null, $this->transport('{"message":{"label":"ok"}}'));
        $configured = $base
            ->withMetadata('authorization', 'Bearer t')
            ->withMetadata('x-tenant', 'acme')
            ->withDeadline(1.5);

        $configured->Echo(new Inner('x'));
        $env = $this->lastEnvelope();
        $this->assertSame(['authorization' => ['Bearer t'], 'x-tenant' => ['acme']], $env['metadata']);
        $this->assertSame(1.5, $env['deadline_seconds']);

        // Immutability: the base client carries neither.
        $this->calls = [];
        $base->Echo(new Inner('x'));
        $env2 = $this->lastEnvelope();
        $this->assertSame([], $env2['metadata']);
        $this->assertNull($env2['deadline_seconds']);
    }

    public function testMalformedResponseThrowsInternal(): void
    {
        $client = new EchoTestClient(null, $this->transport('"not an object"'));
        $this->expectException(GrpcException::class);
        $this->expectExceptionCode(13);
        $client->Echo(new Inner('x'));
    }
}
