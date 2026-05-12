<?php

declare(strict_types=1);

namespace Folk\Sdk\Worker;

use Folk\Sdk\Grpc\GrpcModeHandler;
use Folk\Sdk\Http\HttpModeHandler;
use Folk\Sdk\Jobs\JobsModeHandler;

/**
 * Common interface for all Folk worker loop implementations.
 *
 * Framework adapters (e.g. folk-laravel) receive a HandlerLoop in
 * their boot hook and register handlers/resetters without caring
 * which transport is active (pipe, fork, or embed).
 */
interface HandlerLoop
{
    /**
     * Register an HTTP handler.
     */
    public function registerHttpHandler(HttpModeHandler $handler): void;

    /**
     * Register a jobs handler.
     */
    public function registerJobsHandler(JobsModeHandler $handler): void;

    /**
     * Register a gRPC handler.
     */
    public function registerGrpcHandler(GrpcModeHandler $handler): void;

    /**
     * Register a resetter — called after each request to clean up state.
     */
    public function registerResetter(object $resetter): void;

    /**
     * Register a generic RPC method handler.
     *
     * @param callable(mixed): mixed $handler
     */
    public function register(string $method, callable $handler): void;

    /**
     * Start the loop.
     *
     * - WorkerLoop: blocks forever (reads from FD)
     * - ForkMasterLoop: blocks forever (master process)
     * - EmbedLoop: registers callback and returns immediately
     */
    public function run(): void;
}
