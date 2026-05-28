<?php

declare(strict_types=1);

namespace Folk\Sdk\Worker;

use Folk\Sdk\Grpc\GrpcModeHandler;
use Folk\Sdk\Http\HttpModeHandler;
use Folk\Sdk\Jobs\JobsModeHandler;
use Folk\Sdk\Reset\ResettableInterface;

/**
 * Common interface for all Folk worker loop implementations.
 *
 * Framework adapters (e.g. folk-laravel) receive a HandlerLoop in
 * their boot hook and register handlers/resetters without caring
 * which implementation is active.
 */
interface HandlerLoop
{
    public function registerHttpHandler(HttpModeHandler $handler): void;

    public function registerJobsHandler(JobsModeHandler $handler): void;

    public function registerGrpcHandler(GrpcModeHandler $handler): void;

    public function registerResetter(ResettableInterface $resetter): void;

    /**
     * @param callable(mixed): mixed $handler
     */
    public function register(string $method, callable $handler): void;

    public function run(): void;
}
