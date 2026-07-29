<?php

declare(strict_types=1);

namespace Folk\Sdk\Grpc;

interface GrpcModeHandler
{
    /**
     * Handle a gRPC call.
     *
     * The {@see GrpcRequest} carries both tiers (phase 87):
     *  - transcode: `$request->message` is the decoded request array — the handler
     *    runs on a hydrated DTO and its DTO result is returned as a structured
     *    message (`['__message' => array]`).
     *  - passthrough: `$request->payload` is raw protobuf bytes — the handler
     *    returns raw response bytes (a string).
     *
     * For a **server-streaming** method (phase 88b, #32) the handler instead
     * returns a `Traversable` of response messages — typically a generator that
     * `yield`s one `array<string, mixed>` DTO per message. The WorkerLoop drains
     * it and frames each value as a separate gRPC message.
     *
     * @return string|array<string, mixed>|\Traversable<mixed, array<string, mixed>>|null
     *         Raw protobuf bytes (passthrough), a `['__message' => array]`
     *         envelope (unary transcode), a `Traversable` of DTO arrays
     *         (server-streaming), or null when the handler reported a business
     *         status via `$context->setStatus()`.
     */
    public function call(GrpcRequest $request): string|array|\Traversable|null;
}
