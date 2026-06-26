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
        /**
         * True when the body is streamed (server runs with
         * `stream_request_body` for this path): `$body` is empty and the body
         * must be pulled via {@see \Folk\Sdk\Http\StreamedBody} / `Folk::read`.
         */
        public readonly bool $bodyStream = false,
        /**
         * True when the streamed body is `multipart/form-data` parsed on the
         * Rust side: read parts via `Folk::nextPart()` (see {@see StreamedBody}).
         * Always implies {@see $bodyStream}.
         */
        public readonly bool $multipart = false,
    ) {}

    public static function fromPayload(mixed $payload): self
    {
        $bodyStream = ($payload['body_stream'] ?? false) === true;

        // In streaming mode the payload carries no 'body'; it is delivered as a
        // chunk/part stream and pulled lazily by the adapter.
        $body = '';
        if (!$bodyStream) {
            $body = (string) ($payload['body'] ?? '');
            if (($payload['body_encoding'] ?? null) === 'base64') {
                $body = base64_decode($body, true) ?: '';
            }
        }

        return new self(
            method:     (string) ($payload['method']  ?? 'GET'),
            uri:        (string) ($payload['uri']     ?? '/'),
            headers:    (array)  ($payload['headers'] ?? []),
            body:       $body,
            bodyStream: $bodyStream,
            multipart:  ($payload['multipart'] ?? false) === true,
        );
    }
}
