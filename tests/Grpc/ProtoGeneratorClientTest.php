<?php declare(strict_types=1);

use Folk\Sdk\Grpc\Client\GrpcClient;
use Folk\Sdk\Grpc\Codegen\FdsParser;
use Folk\Sdk\Grpc\Codegen\ProtoGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Phase 88 §2.1 — client-role codegen. Generates `{Service}Client` stubs from the
 * same `complex.pb` fixture used by the server-role test, loads them, and drives a
 * real unary call through an injected transport — the PHP counterpart to the Rust
 * client round-trip. Also asserts the server role is unchanged (no regression).
 */
final class ProtoGeneratorClientTest extends TestCase
{
    private const NS = 'Folk\\Sdk\\Tests\\GeneratedClient';

    /** @var array<string, string> */
    private static array $generated = [];

    public static function setUpBeforeClass(): void
    {
        $fds = file_get_contents(__DIR__ . '/../fixtures/complex.pb');
        self::assertNotFalse($fds, 'complex.pb fixture present');

        $files = FdsParser::parse($fds);
        self::$generated = (new ProtoGenerator($files, self::NS, ProtoGenerator::ROLE_CLIENT, 'catalog'))
            ->generate();

        $dir = sys_get_temp_dir() . '/folk-gen-client-' . getmypid();
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        foreach (self::$generated as $name => $source) {
            $path = $dir . '/' . $name;
            file_put_contents($path, $source);
            require_once $path;
        }
    }

    public function testEmitsClientStubNotInterface(): void
    {
        $this->assertArrayHasKey('ComplexServiceClient.php', self::$generated);
        $this->assertArrayNotHasKey('ComplexServiceInterface.php', self::$generated);
        // DTOs/enums are emitted for both roles.
        $this->assertArrayHasKey('Everything.php', self::$generated);
        $this->assertArrayHasKey('Color.php', self::$generated);
    }

    public function testClientStubShape(): void
    {
        $src = self::$generated['ComplexServiceClient.php'];
        $this->assertStringContainsString('extends GrpcClient', $src);
        $this->assertStringContainsString("public const CLIENT = 'catalog';", $src);
        $this->assertStringContainsString("public const SERVICE = 'folk.test.complex.ComplexService';", $src);
        $this->assertStringContainsString('public function Echo(', $src);
        $this->assertStringContainsString("\$this->call('Echo'", $src);
    }

    public function testGeneratedClientRoundTrips(): void
    {
        $clientClass = self::NS . '\\ComplexServiceClient';
        $everything = self::NS . '\\Everything';
        $this->assertTrue(class_exists($clientClass));
        $this->assertTrue(is_subclass_of($clientClass, GrpcClient::class));

        $captured = null;
        $transport = function (string $method, string $payload) use (&$captured): string {
            $captured = [$method, $payload];
            return '{"message":{"name":"echoed"}}';
        };

        /** @var GrpcClient $client */
        $client = new $clientClass(null, $transport);
        /** @var object $resp */
        $resp = $client->Echo(new $everything(name: 'hi'));

        $this->assertSame('echoed', $resp->getName());
        $this->assertIsArray($captured);
        $this->assertSame('grpc.client.call', $captured[0]);
        /** @var array<string, mixed> $env */
        $env = json_decode($captured[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('catalog', $env['client']);
        $this->assertSame('folk.test.complex.ComplexService', $env['service']);
        $this->assertSame('Echo', $env['method']);
        $this->assertIsArray($env['message']);
        $this->assertSame('hi', $env['message']['name']);
    }

    public function testServerRoleStillEmitsInterface(): void
    {
        $fds = file_get_contents(__DIR__ . '/../fixtures/complex.pb');
        self::assertNotFalse($fds);
        $files = FdsParser::parse($fds);
        $server = (new ProtoGenerator($files, self::NS . '\\Srv'))->generate();
        $this->assertArrayHasKey('ComplexServiceInterface.php', $server);
        $this->assertArrayNotHasKey('ComplexServiceClient.php', $server);
    }
}
