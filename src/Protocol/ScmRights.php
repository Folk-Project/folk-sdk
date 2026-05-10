<?php

declare(strict_types=1);

namespace Folk\Sdk\Protocol;

/**
 * Receive file descriptors via SCM_RIGHTS (Unix ancillary data).
 *
 * Requires ext-sockets. Used by ForkMasterLoop to receive
 * task/control socket FDs from the Rust runtime.
 */
final class ScmRights
{
    /**
     * Receive file descriptors from a socket via SCM_RIGHTS.
     *
     * @param \Socket $socket A connected Unix socket
     * @return list<int> Received file descriptor numbers
     * @throws \RuntimeException on failure
     */
    public static function receiveFds(\Socket $socket): array
    {
        // controllen must be large enough for SCM_RIGHTS ancillary data.
        // Each FD is 4 bytes; CMSG header is ~16 bytes; we expect 2 FDs.
        $msg = [
            'iov' => [
                ['iov_base' => '', 'iov_len' => 1],
            ],
            'control' => [],
            'controllen' => 256,
        ];

        $result = socket_recvmsg($socket, $msg, 0);

        if ($result === false) {
            throw new \RuntimeException(
                'socket_recvmsg failed: ' . socket_strerror(socket_last_error($socket))
            );
        }

        if ($result === 0) {
            throw new \RuntimeException('socket_recvmsg: peer closed connection');
        }

        $fds = [];

        foreach ($msg['control'] as $cmsg) {
            if (isset($cmsg['cmsg_level']) && $cmsg['cmsg_level'] === SOL_SOCKET) {
                $fds = $cmsg['cmsg_data'] ?? [];
                break;
            }
        }

        if ($fds === []) {
            throw new \RuntimeException('No file descriptors received via SCM_RIGHTS');
        }

        return $fds;
    }
}
