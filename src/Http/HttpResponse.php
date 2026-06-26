<?php declare(strict_types=1);

namespace Folk\Sdk\Http;

final class HttpResponse
{
    public function __construct(
        public int $status = 200,
        /** @var array<string, string> */
        public array $headers = [],
        public string $body = '',
    ) {}

    /**
     * Sentinel for a response already streamed via `Folk::writeHead/write/end`.
     *
     * Once the handler has streamed the response, Folk ignores the handler's
     * return value (the stream is finalised by `folk_write_end`), so this just
     * needs to be a valid, empty {@see HttpResponse}.
     */
    public static function alreadyStreamed(): self
    {
        return new self();
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        $payload = [
            'status'  => $this->status,
            'headers' => $this->headers,
            'body'    => $this->body,
        ];

        if (!mb_check_encoding($this->body, 'UTF-8')) {
            $payload['body'] = base64_encode($this->body);
            $payload['body_encoding'] = 'base64';
        }

        return $payload;
    }
}
