<?php

/**
 * PHPStan stubs for folk PHP extension functions.
 */

function folk_version(): string {}
function folk_is_worker_thread(): bool {}
function folk_worker_run(string $callback): void {}
function folk_call(string $method, string $payload): string {}
function folk_request_id(): string {}
