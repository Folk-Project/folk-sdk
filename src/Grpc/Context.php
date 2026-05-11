<?php

declare(strict_types=1);

namespace Folk\Sdk\Grpc;

/**
 * gRPC call context — carries metadata (headers), deadlines, etc.
 */
final class Context
{
    /** @var array<string, string> */
    private array $metadata;

    /**
     * @param array<string, string> $metadata
     */
    public function __construct(array $metadata = [])
    {
        $this->metadata = $metadata;
    }

    /**
     * Get a metadata value by key (case-insensitive).
     */
    public function getValue(string $key): ?string
    {
        $key = strtolower($key);
        return $this->metadata[$key] ?? null;
    }

    /**
     * Get all metadata as key-value pairs.
     *
     * @return array<string, string>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
