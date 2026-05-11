<?php

declare(strict_types=1);

namespace Folk\Sdk\Rpc;

use Folk\Sdk\Protocol\FrameReader;
use Folk\Sdk\Protocol\FrameWriter;
use Folk\Sdk\Protocol\RpcMessage;

/**
 * Simple RPC client for Folk admin socket.
 *
 * Connects to the Unix socket, sends a request, and returns the response.
 */
final class RpcClient
{
    private static int $nextMsgId = 1;

    /** @var resource|null */
    private $stream = null;
    private ?FrameReader $reader = null;
    private ?FrameWriter $writer = null;

    public function __construct(
        private readonly string $socketPath,
    ) {}

    /**
     * Call an RPC method and return the result.
     *
     * @throws \RuntimeException on connection or protocol error
     */
    public function call(string $method, mixed $params = null): mixed
    {
        $this->connect();

        $msgId = self::$nextMsgId++;
        $this->writer->write(RpcMessage::request($msgId, $method, $params));

        $response = $this->reader->read();
        if ($response === null) {
            throw new \RuntimeException("RPC: EOF from server");
        }

        if ($response->type !== RpcMessage::TYPE_RESPONSE) {
            throw new \RuntimeException("RPC: expected response, got type {$response->type}");
        }

        if ($response->error !== null) {
            $msg = is_array($response->error) ? ($response->error['message'] ?? 'unknown') : (string) $response->error;
            throw new \RuntimeException("RPC error: {$msg}");
        }

        return $response->result;
    }

    private function connect(): void
    {
        if ($this->stream !== null) {
            return;
        }

        $this->stream = @stream_socket_client("unix://{$this->socketPath}", $errno, $errstr, 5);
        if ($this->stream === false) {
            throw new \RuntimeException("RPC: cannot connect to {$this->socketPath}: {$errstr}");
        }

        stream_set_blocking($this->stream, true);
        $this->reader = new FrameReader($this->stream);
        $this->writer = new FrameWriter($this->stream);
    }

    public function __destruct()
    {
        if ($this->stream !== null) {
            fclose($this->stream);
        }
    }
}
