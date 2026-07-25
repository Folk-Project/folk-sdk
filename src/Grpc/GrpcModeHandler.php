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
     * @return string|array<string, mixed>|null Raw protobuf bytes (passthrough), a
     *         `['__message' => array]` envelope (transcode), or null when the
     *         handler reported a business status via `$context->setStatus()`.
     */
    public function call(GrpcRequest $request): string|array|null;
}
