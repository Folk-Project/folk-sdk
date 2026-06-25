<?php

/**
 * PHPStan stubs for folk PHP extension functions.
 */

function folk_version(): string {}
function folk_is_worker_thread(): bool {}
function folk_worker_run(string $callback): void {}
function folk_call(string $method, string $payload): string {}
function folk_request_id(): string {}
function folk_write_head(int $status, string $headers_json): void {}
function folk_write(string $data): void {}
function folk_write_end(): void {}
