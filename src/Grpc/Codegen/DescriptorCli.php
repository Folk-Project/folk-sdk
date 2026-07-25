<?php

declare(strict_types=1);

namespace Folk\Sdk\Grpc\Codegen;

/**
 * `folk-server grpc:descriptors` submode (phase 87 task 1.4).
 *
 * Compiles `.proto` files to an encoded FileDescriptorSet and writes the raw
 * bytes to stdout — WITHOUT bootstrapping the framework, binding listeners, or
 * forking workers. It is the subprocess half of the descriptor-export contract:
 * the code generator (folk-sdk) shells out to `vendor/bin/folk-server
 * grpc:descriptors proto/a.proto proto/b.proto …` when the extension is not
 * loaded in the generating PHP process, and reads the FDS from stdout.
 *
 * The generating protobuf compiler lives in the Rust gRPC plugin (protox), so
 * this relies on the `folk_grpc_descriptors()` extension function being present
 * — which it is whenever the loaded `folk` build includes the gRPC plugin. The
 * folk extension is loaded here through the process php.ini, exactly like every
 * other `folk-server` invocation.
 *
 * Contract:
 *   - stdout  → raw FileDescriptorSet bytes (nothing else)
 *   - stderr  → diagnostics / usage / errors
 *   - exit 0  → success, 1 → failure
 */
final class DescriptorCli
{
    /** CLI argument that selects this submode. */
    public const COMMAND = 'grpc:descriptors';

    /**
     * Run the submode and terminate the process when `$argv` selects it.
     *
     * A no-op (returns normally) when the first argument is not
     * {@see self::COMMAND}, so entry points can call this before their normal
     * bootstrap without affecting the default server path.
     *
     * @param list<string> $argv The process `$argv` (argv[0] = script name).
     */
    public static function maybeHandle(array $argv): void
    {
        if (($argv[1] ?? null) !== self::COMMAND) {
            return;
        }

        $paths = array_slice($argv, 2);

        if ($paths === []) {
            self::fail('usage: folk-server ' . self::COMMAND . ' <file.proto> [<file.proto> …]');
        }

        if (!\function_exists('folk_grpc_descriptors')) {
            self::fail(
                'folk_grpc_descriptors() is unavailable: the loaded folk extension '
                . 'was built without the gRPC plugin.'
            );
        }

        try {
            $fds = \folk_grpc_descriptors($paths);
        } catch (\Throwable $e) {
            self::fail('descriptor compilation failed: ' . $e->getMessage());
        }

        fwrite(STDOUT, $fds);
        exit(0);
    }

    /**
     * Write a diagnostic line to stderr and exit non-zero. Never returns.
     *
     * @return never
     */
    private static function fail(string $message): void
    {
        fwrite(STDERR, 'folk-server ' . self::COMMAND . ': ' . $message . "\n");
        exit(1);
    }
}
