<?php

declare(strict_types=1);

namespace Folk\Sdk\Tests;

use Folk\Sdk\Protocol\ScmRights;
use PHPUnit\Framework\TestCase;

/**
 * @requires extension sockets
 */
final class ScmRightsTest extends TestCase
{
    public function test_throws_when_peer_sends_no_fds(): void
    {
        $sockets = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets);
        [$sender, $receiver] = $sockets;

        // Send a normal byte (no ancillary data / no FDs)
        socket_write($sender, 'x', 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No file descriptors received');

        ScmRights::receiveFds($receiver);
    }

    public function test_throws_when_peer_closes_connection(): void
    {
        $sockets = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets);
        [$sender, $receiver] = $sockets;

        // Close sender side — recvmsg should return 0
        socket_close($sender);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('peer closed connection');

        ScmRights::receiveFds($receiver);
    }
}
