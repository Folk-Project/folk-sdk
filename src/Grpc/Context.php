<?php

declare(strict_types=1);

namespace Folk\Sdk\Grpc;

/**
 * gRPC call context — carries metadata (headers), the deadline, peer info, and
 * the business status the handler may set.
 *
 * Metadata is stored as a multimap (`array<string, list<string>>`): HTTP/2 allows
 * a header key to repeat, and folding duplicates loses data. {@see getValue()}
 * and {@see getMetadata()} return the FIRST value for backward compatibility;
 * {@see getAll()} and {@see getMetadataMulti()} expose the full multimap.
 */
final class Context
{
    /** @var array<string, list<string>> lower-cased key → all values */
    private array $metadata;

    /** @var array{code: int, message: string}|null */
    private ?array $status = null;

    /** Monotonic start (seconds) for {@see remaining()}; null when no deadline. */
    private ?float $startedAt;

    /**
     * @param array<string, string|list<string>> $metadata map or multimap; both
     *        forms are normalised to a lower-cased multimap.
     */
    public function __construct(
        array $metadata = [],
        private readonly string $service = '',
        private readonly string $method = '',
        private readonly ?string $requestId = null,
        private readonly ?float $timeoutSeconds = null,
        private readonly ?string $peerAddress = null,
        private readonly ?string $authority = null,
    ) {
        $normalized = [];
        foreach ($metadata as $key => $value) {
            $lower = strtolower((string) $key);
            $normalized[$lower] = is_array($value)
                ? array_map(strval(...), $value)
                : [(string) $value];
        }
        $this->metadata = $normalized;
        $this->startedAt = $timeoutSeconds !== null ? hrtime(true) / 1e9 : null;
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

    /** Whether a metadata key is present (case-insensitive). */
    public function has(string $key): bool
    {
        return isset($this->metadata[strtolower($key)]);
    }

    /** First metadata value for a key (case-insensitive), or null. */
    public function getValue(string $key): ?string
    {
        return $this->metadata[strtolower($key)][0] ?? null;
    }

    /**
     * All metadata values for a key (case-insensitive), in arrival order.
     *
     * @return list<string>
     */
    public function getAll(string $key): array
    {
        return $this->metadata[strtolower($key)] ?? [];
    }

    /**
     * All metadata as first-value pairs (backward-compatible view).
     *
     * @return array<string, string>
     */
    public function getMetadata(): array
    {
        return array_map(static fn (array $v): string => $v[0] ?? '', $this->metadata);
    }

    /**
     * The full metadata multimap.
     *
     * @return array<string, list<string>>
     */
    public function getMetadataMulti(): array
    {
        return $this->metadata;
    }

    /**
     * Decode a binary metadata value (gRPC `-bin` convention): the wire value is
     * base64. Returns the first value's raw bytes, or null if absent/invalid.
     */
    public function getBinary(string $key): ?string
    {
        $value = $this->getValue($key);
        if ($value === null) {
            return null;
        }
        $decoded = base64_decode($value, true);
        return $decoded === false ? null : $decoded;
    }

    /** The `authorization` metadata value, if any. */
    public function authorization(): ?string
    {
        return $this->getValue('authorization');
    }

    /** The bearer token from `Authorization: Bearer <token>`, if present. */
    public function bearerToken(): ?string
    {
        $auth = $this->authorization();
        if ($auth === null) {
            return null;
        }
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    /** Fully-qualified service name for this call (e.g. `helloworld.Greeter`). */
    public function service(): string
    {
        return $this->service;
    }

    /** Method name for this call (e.g. `SayHello`). */
    public function method(): string
    {
        return $this->method;
    }

    /** Correlation id (UUID v7) for this call, or `''` if none. */
    public function requestId(): string
    {
        return $this->requestId ?? '';
    }

    /** Whether the client sent a deadline (`grpc-timeout`). */
    public function hasDeadline(): bool
    {
        return $this->timeoutSeconds !== null;
    }

    /** The client's timeout in seconds relative to call start, or null. */
    public function timeoutSeconds(): ?float
    {
        return $this->timeoutSeconds;
    }

    /**
     * Seconds left before the deadline (never negative), or null if no deadline.
     *
     * Advisory: Folk cannot force-kill a blocking worker mid-call, so this only
     * helps handlers that check it themselves.
     */
    public function remaining(): ?float
    {
        if ($this->timeoutSeconds === null || $this->startedAt === null) {
            return null;
        }
        $elapsed = hrtime(true) / 1e9 - $this->startedAt;
        $remaining = $this->timeoutSeconds - $elapsed;
        return $remaining > 0 ? $remaining : 0.0;
    }

    /**
     * Remote peer address, or null. Behind a proxy this is the proxy's address;
     * the real client arrives via `X-Forwarded-For` (as on the HTTP path).
     */
    public function peerAddress(): ?string
    {
        return $this->peerAddress;
    }

    /** The `:authority` (host) of the request, or null. */
    public function authority(): ?string
    {
        return $this->authority;
    }
}
