<?php
/**
 * Global dispatch function called by Rust via call_user_function.
 *
 * This is the zero-copy path: Rust passes method + params as PHP arrays
 * directly (no JSON encode/decode). Returns a PHP array.
 *
 * @param string               $method RPC method name (e.g. "http.handle")
 * @param array<string, mixed> $params Request parameters as associative array
 * @return array<string, mixed> Response as associative array
 */
function __folk_dispatch(string $method, array $params): array
{
    /** @var \Folk\Sdk\Worker\WorkerLoop|null $loop */
    $loop = $GLOBALS['__folk_worker_loop'] ?? null;
    if ($loop === null) {
        return ['__error' => 'no worker loop registered'];
    }

    return $loop->dispatchDirect($method, $params);
}
