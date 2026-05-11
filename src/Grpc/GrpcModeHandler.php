<?php

declare(strict_types=1);

namespace Folk\Sdk\Grpc;

interface GrpcModeHandler
{
    /**
     * Handle a gRPC call.
     *
     * @param string $service Fully-qualified service name (e.g. "helloworld.Greeter")
     * @param string $method  Method name (e.g. "SayHello")
     * @param string $payload Raw protobuf bytes
     * @param Context $context gRPC metadata (headers, auth tokens, etc.)
     * @return string Raw protobuf response bytes
     */
    public function call(string $service, string $method, string $payload, Context $context): string;
}
