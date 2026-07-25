<?php declare(strict_types=1);

use Folk\Sdk\Grpc\Codegen\DescriptorCli;
use PHPUnit\Framework\TestCase;

final class DescriptorCliTest extends TestCase
{
    /**
     * The critical safety property: on a normal server launch (no
     * `grpc:descriptors` argument) the submode must be a pure no-op and return
     * control to the entry point. A regression here would break every
     * `folk-server` / `folk-worker` start.
     *
     * @param list<string> $argv
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('nonSubmodeArgv')]
    public function testMaybeHandleIsNoOpForNormalLaunch(array $argv): void
    {
        DescriptorCli::maybeHandle($argv);
        // Reaching this line means the call returned instead of exiting.
        $this->assertTrue(true);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function nonSubmodeArgv(): iterable
    {
        yield 'no args' => [['folk-server']];
        yield 'config path only' => [['folk-server', 'folk.toml']];
        yield 'other subcommand' => [['folk-server', 'serve', 'folk.toml']];
        yield 'lookalike prefix' => [['folk-server', 'grpc:descriptorsX']];
    }

    public function testCommandConstant(): void
    {
        // The generator and entry points share this literal; pin it.
        $this->assertSame('grpc:descriptors', DescriptorCli::COMMAND);
    }
}
