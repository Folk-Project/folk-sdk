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

    /** @var array{code: int, message: string}|null */
    private ?array $status = null;

    /**
     * @param array<string, string> $metadata
     */
    public function __construct(array $metadata = [])
    {
        $this->metadata = $metadata;
    }

    /**
     * Set the gRPC response status (a business outcome, not a fatal error).
     *
     * Standard server-side gRPC idiom: the handler reports the outcome via the
     * context and returns null. `$code` is a canonical gRPC status code
     * (google.rpc.Code), e.g. 5 = NOT_FOUND, 3 = INVALID_ARGUMENT,
     * 7 = PERMISSION_DENIED, 16 = UNAUTHENTICATED.
     */
    public function setStatus(int $code, string $message = ''): void
    {
        $this->status = ['code' => $code, 'message' => $message];
    }

    /**
     * Get the status set via {@see setStatus()}, or null if none was set.
     *
     * @return array{code: int, message: string}|null
     */
    public function getStatus(): ?array
    {
        return $this->status;
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
