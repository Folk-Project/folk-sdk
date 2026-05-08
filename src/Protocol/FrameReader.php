<?php

declare(strict_types=1);

namespace Folk\Sdk\Protocol;

use Folk\Sdk\Protocol\Exception\ProtocolException;

/**
 * Reads length-prefixed MessagePack-RPC frames from a stream.
 *
 * Wire format: [4-byte BE length][MessagePack payload]
 * Maximum frame size: 16 MiB.
 */
final class FrameReader
{
    private const MAX_FRAME_SIZE = 16 * 1024 * 1024;

    /** @var resource */
    private $stream;

    /**
     * @param resource $stream A readable stream (e.g. fopen('php://fd/3', 'rb'))
     */
    public function __construct($stream)
    {
        $this->stream = $stream;
    }

    /**
     * Read a single frame from the stream.
     *
     * Returns null on EOF (stream closed). Throws on malformed data.
     */
    public function read(): ?RpcMessage
    {
        // Read 4-byte big-endian length prefix.
        $header = $this->readExact(4);
        if ($header === null) {
            return null; // EOF
        }

        $unpacked = unpack('Nlen', $header);
        $length = $unpacked['len'];

        if ($length > self::MAX_FRAME_SIZE) {
            throw new ProtocolException("Frame too large: {$length} bytes (limit " . self::MAX_FRAME_SIZE . ")");
        }

        if ($length === 0) {
            throw new ProtocolException('Empty frame');
        }

        // Read the payload.
        $payload = $this->readExact($length);
        if ($payload === null) {
            throw new ProtocolException('Unexpected EOF while reading frame payload');
        }

        // Decode MessagePack.
        $data = msgpack_unpack($payload);
        if (!is_array($data)) {
            throw new ProtocolException('Frame payload is not a MessagePack array');
        }

        return RpcMessage::decode($data);
    }

    /**
     * Read exactly $length bytes from the stream.
     *
     * Returns null on clean EOF (0 bytes available). Throws on partial read.
     */
    private function readExact(int $length): ?string
    {
        $buffer = '';
        $remaining = $length;

        while ($remaining > 0) {
            $chunk = fread($this->stream, $remaining);

            if ($chunk === false || $chunk === '') {
                if ($buffer === '') {
                    return null; // Clean EOF
                }
                throw new ProtocolException(
                    "Unexpected EOF: read " . strlen($buffer) . " of {$length} bytes"
                );
            }

            $buffer .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $buffer;
    }
}
