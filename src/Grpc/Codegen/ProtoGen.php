<?php

declare(strict_types=1);

namespace Folk\Sdk\Grpc\Codegen;

/**
 * The one-call code-generation facade shared by `bin/folk-grpc-gen` and every
 * adapter's `folk:grpc:generate` command (phase 87): obtain the descriptor set,
 * generate DTOs/enums/interfaces, and write them to a target directory.
 *
 * Generation is idempotent (decision 7): the target directory is overwritten,
 * every file carries a `@generated` header. Existing hand-written files with
 * other names are left untouched.
 */
final class ProtoGen
{
    /**
     * Generate PHP from `.proto` files into `$outDir`.
     *
     * @param list<string> $protoPaths .proto files (relative to the CWD)
     * @param ProtoGenerator::ROLE_* $role       server (`*Interface`) or client (`*Client`)
     * @param string                 $clientName `[grpc.clients.<name>]` baked into client stubs
     * @return list<string> absolute paths of the files written
     */
    public static function run(
        array $protoPaths,
        string $outDir,
        string $namespace,
        ?DescriptorProvider $provider = null,
        string $role = ProtoGenerator::ROLE_SERVER,
        string $clientName = '',
    ): array {
        $provider ??= new DescriptorProvider();
        $fds = $provider->fetch($protoPaths);
        return self::fromFds($fds, $outDir, $namespace, $role, $clientName);
    }

    /**
     * Generate PHP from an already-obtained encoded `FileDescriptorSet`.
     *
     * @param ProtoGenerator::ROLE_* $role
     * @return list<string> absolute paths of the files written
     */
    public static function fromFds(
        string $fds,
        string $outDir,
        string $namespace,
        string $role = ProtoGenerator::ROLE_SERVER,
        string $clientName = '',
    ): array {
        $files = FdsParser::parse($fds);
        $generated = (new ProtoGenerator($files, trim($namespace, '\\'), $role, $clientName))->generate();

        // Phase 90: `{client_name}` in the output dir mirrors the namespace
        // (`.../Client/{client_name}`); `{package}` is already baked into each
        // generated path as a sub-directory.
        $outDir = str_replace('{client_name}', $clientName, $outDir);

        if (!is_dir($outDir) && !mkdir($outDir, 0o777, true) && !is_dir($outDir)) {
            throw new \RuntimeException("cannot create output directory: {$outDir}");
        }

        $written = [];
        foreach ($generated as $name => $source) {
            $path = rtrim($outDir, '/') . '/' . $name;
            // Package layout (phase 90) nests paths (Io/Altessa/.../X.php) — create
            // the sub-directory before writing.
            $sub = \dirname($path);
            if ($sub !== $outDir && !is_dir($sub) && !mkdir($sub, 0o777, true) && !is_dir($sub)) {
                throw new \RuntimeException("cannot create output directory: {$sub}");
            }
            if (file_put_contents($path, $source) === false) {
                throw new \RuntimeException("cannot write generated file: {$path}");
            }
            $written[] = $path;
        }

        sort($written);
        return $written;
    }
}
