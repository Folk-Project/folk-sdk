<?php

declare(strict_types=1);

namespace Folk\Sdk\Tests;

use Folk\Sdk\Protocol\Exception\ProtocolException;
use Folk\Sdk\Protocol\RpcMessage;
use PHPUnit\Framework\TestCase;

final class RpcMessageTest extends TestCase
{
    public function test_request_roundtrip(): void
    {
        $msg = RpcMessage::request(1, 'echo', ['hello']);
        $encoded = $msg->encode();
        $decoded = RpcMessage::decode($encoded);

        $this->assertSame(RpcMessage::TYPE_REQUEST, $decoded->type);
        $this->assertSame(1, $decoded->msgid);
        $this->assertSame('echo', $decoded->method);
        $this->assertSame(['hello'], $decoded->params);
    }

    public function test_response_roundtrip(): void
    {
        $msg = RpcMessage::response(1, null, 'pong');
        $encoded = $msg->encode();
        $decoded = RpcMessage::decode($encoded);

        $this->assertSame(RpcMessage::TYPE_RESPONSE, $decoded->type);
        $this->assertSame(1, $decoded->msgid);
        $this->assertNull($decoded->error);
        $this->assertSame('pong', $decoded->result);
    }

    public function test_notify_roundtrip(): void
    {
        $msg = RpcMessage::notify('control.ready', ['pid' => 42]);
        $encoded = $msg->encode();
        $decoded = RpcMessage::decode($encoded);

        $this->assertSame(RpcMessage::TYPE_NOTIFY, $decoded->type);
        $this->assertSame('control.ready', $decoded->method);
        $this->assertSame(['pid' => 42], $decoded->params);
    }

    public function test_decode_rejects_short_array(): void
    {
        $this->expectException(ProtocolException::class);
        RpcMessage::decode([0, 1]);
    }

    public function test_decode_rejects_unknown_type(): void
    {
        $this->expectException(ProtocolException::class);
        RpcMessage::decode([99, 'foo', 'bar']);
    }
}
