<?php declare(strict_types=1);

namespace Folk\Sdk\Http;

final readonly class HttpRequest
{
    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        /** @var array<string, string> */
        public readonly array $headers,
        public readonly string $body,
    ) {}

    public static function fromPayload(mixed $payload): self
    {
        $body = (string) ($payload['body'] ?? '');
        if (($payload['body_encoding'] ?? null) === 'base64') {
            $body = base64_decode($body, true) ?: '';
        }

        return new self(
            method: (string) ($payload['method'] ?? 'GET'),
            uri:    (string) ($payload['uri']    ?? '/'),
            headers: (array)  ($payload['headers'] ?? []),
            body:   $body,
        );
    }
}
