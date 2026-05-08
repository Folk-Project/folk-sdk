<?php

declare(strict_types=1);

namespace Folk\Sdk\Protocol;

use Folk\Sdk\Protocol\Exception\ProtocolException;

/**
 * A single MessagePack-RPC message.
 *
 * Three message variants, each serialized as a positional MessagePack array:
 *
 * - Request:  [0, msgid, method, params]
 * - Response: [1, msgid, error, result]
 * - Notify:   [2, method, params]
 */
final class RpcMessage
{
    public const TYPE_REQUEST  = 0;
    public const TYPE_RESPONSE = 1;
    public const TYPE_NOTIFY   = 2;

    public readonly int $type;
    public readonly ?int $msgid;
    public readonly ?string $method;
    public readonly mixed $params;
    public readonly mixed $error;
    public readonly mixed $result;

    private function __construct(
        int $type,
        ?int $msgid = null,
        ?string $method = null,
        mixed $params = null,
        mixed $error = null,
        mixed $result = null,
    ) {
        $this->type   = $type;
        $this->msgid  = $msgid;
        $this->method = $method;
        $this->params = $params;
        $this->error  = $error;
        $this->result = $result;
    }

    /**
     * Construct a Request message: [0, msgid, method, params].
     */
    public static function request(int $msgid, string $method, mixed $params): self
    {
        return new self(self::TYPE_REQUEST, msgid: $msgid, method: $method, params: $params);
    }

    /**
     * Construct a Response message: [1, msgid, error, result].
     */
    public static function response(int $msgid, mixed $error, mixed $result): self
    {
        return new self(self::TYPE_RESPONSE, msgid: $msgid, error: $error, result: $result);
    }

    /**
     * Construct a Notify message: [2, method, params].
     */
    public static function notify(string $method, mixed $params): self
    {
        return new self(self::TYPE_NOTIFY, method: $method, params: $params);
    }

    /**
     * Encode to a MessagePack array.
     *
     * @return list<mixed>
     */
    public function encode(): array
    {
        return match ($this->type) {
            self::TYPE_REQUEST  => [$this->type, $this->msgid, $this->method, $this->params],
            self::TYPE_RESPONSE => [$this->type, $this->msgid, $this->error, $this->result],
            self::TYPE_NOTIFY   => [$this->type, $this->method, $this->params],
        };
    }

    /**
     * Decode from a MessagePack array.
     *
     * @param list<mixed> $data
     */
    public static function decode(array $data): self
    {
        if (count($data) < 3) {
            throw new ProtocolException('RPC message must have at least 3 elements');
        }

        $type = $data[0];

        return match ($type) {
            self::TYPE_REQUEST => self::decodeRequest($data),
            self::TYPE_RESPONSE => self::decodeResponse($data),
            self::TYPE_NOTIFY => self::decodeNotify($data),
            default => throw new ProtocolException("Unknown message type tag: {$type}"),
        };
    }

    private static function decodeRequest(array $data): self
    {
        if (count($data) !== 4) {
            throw new ProtocolException('Request must have exactly 4 elements');
        }

        return self::request(
            msgid: (int) $data[1],
            method: (string) $data[2],
            params: $data[3],
        );
    }

    private static function decodeResponse(array $data): self
    {
        if (count($data) !== 4) {
            throw new ProtocolException('Response must have exactly 4 elements');
        }

        return self::response(
            msgid: (int) $data[1],
            error: $data[2],
            result: $data[3],
        );
    }

    private static function decodeNotify(array $data): self
    {
        if (count($data) !== 3) {
            throw new ProtocolException('Notify must have exactly 3 elements');
        }

        return self::notify(
            method: (string) $data[1],
            params: $data[2],
        );
    }
}
