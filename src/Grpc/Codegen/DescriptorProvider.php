<?php

declare(strict_types=1);

namespace Folk\Sdk\Grpc\Codegen;

/**
 * Obtains an encoded `FileDescriptorSet` for a set of `.proto` files — the client
 * half of the descriptor-export contract (phase 87). No protoc: the compiler is
 * the Rust gRPC plugin (protox), reached two ways:
 *
 *  1. in-process, when the `folk` extension exposes `folk_grpc_descriptors()`
 *     (the generating PHP process has the gRPC build loaded);
 *  2. otherwise a subprocess `folk-server grpc:descriptors <proto…>` whose stdout
 *     is the raw FDS ({@see DescriptorCli}).
 *
 * Proto paths resolve relative to the current working directory (the project
 * root, where the generator is invoked) — the same resolution the server uses.
 */
final class DescriptorProvider
{
    /**
     * @param string|null $serverBin Explicit path to a `folk-server`/`folk-worker`
     *        entry; auto-discovered under `vendor/bin` when null.
     */
    public function __construct(private readonly ?string $serverBin = null) {}

    /**
     * @param list<string> $protoPaths
     * @return string raw FileDescriptorSet bytes
     */
    public function fetch(array $protoPaths): string
    {
        if ($protoPaths === []) {
            throw new \RuntimeException('no .proto files given to descriptor provider');
        }

        if (\function_exists('folk_grpc_descriptors')) {
            return \folk_grpc_descriptors($protoPaths);
        }

        return $this->fetchViaSubprocess($protoPaths);
    }

    /** True when descriptors can be built in-process (no subprocess needed). */
    public static function hasInProcess(): bool
    {
        return \function_exists('folk_grpc_descriptors');
    }

    /**
     * @param list<string> $protoPaths
     */
    private function fetchViaSubprocess(array $protoPaths): string
    {
        $bin = $this->resolveServerBin();
        $cmd = array_merge([PHP_BINARY, $bin, DescriptorCli::COMMAND], $protoPaths);

        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $cwd = getcwd();
        $proc = proc_open($cmd, $spec, $pipes, $cwd === false ? null : $cwd);
        if (!is_resource($proc)) {
            throw new \RuntimeException("failed to launch {$bin} " . DescriptorCli::COMMAND);
        }

        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        if ($code !== 0) {
            $detail = is_string($err) && $err !== '' ? trim($err) : "exit code {$code}";
            throw new \RuntimeException("descriptor export failed: {$detail}");
        }
        if (!is_string($out) || $out === '') {
            throw new \RuntimeException('descriptor export produced no output');
        }

        return $out;
    }

    private function resolveServerBin(): string
    {
        if ($this->serverBin !== null) {
            return $this->serverBin;
        }

        $cwd = getcwd();
        $root = $cwd === false ? '.' : $cwd;
        foreach (['vendor/bin/folk-server', 'vendor/bin/folk-worker'] as $rel) {
            $path = realpath($root . '/' . $rel);
            if ($path !== false) {
                return $path;
            }
        }

        throw new \RuntimeException(
            'cannot find vendor/bin/folk-server (or folk-worker); pass an explicit '
            . 'server binary or run the generator from the project root',
        );
    }
}
