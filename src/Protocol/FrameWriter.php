<?php

declare(strict_types=1);

namespace Folk\Sdk\Protocol;

use Folk\Sdk\Protocol\Exception\ProtocolException;

/**
 * Writes length-prefixed MessagePack-RPC frames to a stream.
 *
 * Wire format: [4-byte BE length][MessagePack payload]
 */
final class FrameWriter
{
    /** @var resource */
    private $stream;

    /**
     * @param resource $stream A writable stream (e.g. fopen('php://fd/3', 'r+b'))
     */
    public function __construct($stream)
    {
        $this->stream = $stream;
    }

    /**
     * Write a single RPC message as a length-prefixed frame.
     */
    public function write(RpcMessage $message): void
    {
        $payload = msgpack_pack($message->encode());
        $length = strlen($payload);
        $frame = pack('N', $length) . $payload;

        $written = fwrite($this->stream, $frame);
        if ($written !== strlen($frame)) {
            throw new ProtocolException("Failed to write frame: wrote {$written} of " . strlen($frame) . " bytes");
        }

        fflush($this->stream);
    }
}
