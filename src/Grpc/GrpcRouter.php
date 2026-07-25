<?php

declare(strict_types=1);

namespace Folk\Sdk\Grpc;

final class GrpcRouter implements GrpcModeHandler
{
    /** @var array<string, object> service name → handler instance */
    private array $services = [];

    private readonly Hydrator $hydrator;

    public function __construct(?Hydrator $hydrator = null)
    {
        $this->hydrator = $hydrator ?? new Hydrator();
    }

    /**
     * Register a gRPC service handler.
     *
     * Supports two formats:
     * - String key: register('greeter.Greeter', $handler)
     * - Interface with NAME constant: register(GreeterInterface::class, $handler)
     */
    public function register(string $serviceNameOrInterface, object $handler): void
    {
        $serviceName = $this->resolveServiceName($serviceNameOrInterface, $handler);
        $this->services[$serviceName] = $handler;
    }

    public function call(GrpcRequest $request): string|array|null
    {
        $handler = $this->services[$request->service]
            ?? throw new \RuntimeException("Unknown gRPC service: {$request->service}");

        if (!method_exists($handler, $request->method)) {
            throw new \RuntimeException("Unknown method: {$request->service}/{$request->method}");
        }

        // Transcode tier (phase 87): the plugin decoded the request into a native
        // array; hydrate it into the handler's DTO and re-encode the DTO result.
        if ($request->transcode) {
            return $this->callTranscoded($handler, $request);
        }

        return $this->callPassthrough($handler, $request);
    }

    /**
     * Transcode tier: `method(RequestDto $request, Context $context): ReplyDto`.
     * The result DTO is flattened back to `['__message' => array]` for the plugin
     * to re-encode; a business status suppresses the body (returns null).
     *
     * @return array{__message: array<string, mixed>}|null
     */
    private function callTranscoded(object $handler, GrpcRequest $request): ?array
    {
        $ref = new \ReflectionMethod($handler, $request->method);
        $dtoClass = $this->requestDtoClass($ref);

        $args = [];
        if ($dtoClass !== null) {
            $args[] = $this->hydrator->hydrate($dtoClass, $request->message ?? []);
        }
        $args[] = $request->context;

        /** @var object|null $result */
        $result = $handler->{$request->method}(...$args);

        if ($request->context->getStatus() !== null) {
            return null;
        }

        if (!is_object($result)) {
            throw new \RuntimeException(
                "Transcoded handler {$request->service}/{$request->method} must return a DTO",
            );
        }

        return ['__message' => $this->hydrator->dehydrate($result)];
    }

    /**
     * Passthrough tier (transcode off): either a legacy protobuf `Message` param
     * (ext-protobuf) or a raw string payload. Returns raw protobuf bytes, or null
     * when the handler set a business status.
     */
    private function callPassthrough(object $handler, GrpcRequest $request): ?string
    {
        $method = $request->method;
        $ref = new \ReflectionMethod($handler, $method);
        $params = $ref->getParameters();
        $lastParam = $params[count($params) - 1] ?? null;

        // A protobuf `Message` last parameter selects the legacy typed path: bind
        // the decoded message and the Context to whichever positions they occupy
        // (handlers use either order, e.g. `(Context, Request)`). Otherwise the
        // handler takes the raw payload string directly.
        if ($lastParam !== null && $this->isProtobufParam($lastParam)) {
            $args = [];
            foreach ($params as $param) {
                $type = $param->getType();
                $typeName = $type instanceof \ReflectionNamedType && !$type->isBuiltin()
                    ? $type->getName()
                    : null;
                if ($typeName !== null && is_subclass_of($typeName, \Google\Protobuf\Internal\Message::class)) {
                    /** @var class-string<\Google\Protobuf\Internal\Message> $typeName */
                    $message = new $typeName();
                    $message->mergeFromString($request->payload);
                    $args[] = $message;
                } else {
                    $args[] = $request->context;
                }
            }

            $result = $handler->$method(...$args);

            if ($request->context->getStatus() !== null) {
                return null;
            }

            /** @var \Google\Protobuf\Internal\Message $result */
            return $result->serializeToString();
        }

        $result = $handler->$method($request->payload);

        if ($request->context->getStatus() !== null) {
            return null;
        }

        return is_string($result) ? $result : null;
    }

    /**
     * The generated request-DTO class of a transcoded handler method: the first
     * parameter that isn't the {@see Context}. Null when the method takes only a
     * context (e.g. a `google.protobuf.Empty` request).
     *
     * @return class-string|null
     */
    private function requestDtoClass(\ReflectionMethod $ref): ?string
    {
        foreach ($ref->getParameters() as $param) {
            $type = $param->getType();
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }
            $name = $type->getName();
            if ($name === Context::class) {
                continue;
            }
            /** @var class-string $name */
            return $name;
        }
        return null;
    }

    private function resolveServiceName(string $nameOrInterface, object $handler): string
    {
        if (defined("{$nameOrInterface}::NAME")) {
            /** @var string */
            return constant("{$nameOrInterface}::NAME");
        }

        foreach (class_implements($handler) as $interface) {
            if (defined("{$interface}::NAME")) {
                /** @var string */
                return constant("{$interface}::NAME");
            }
        }

        return $nameOrInterface;
    }

    private function isProtobufParam(\ReflectionParameter $param): bool
    {
        $type = $param->getType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return false;
        }

        return is_subclass_of($type->getName(), \Google\Protobuf\Internal\Message::class);
    }
}
