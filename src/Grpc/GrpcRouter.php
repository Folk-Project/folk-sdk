<?php

declare(strict_types=1);

namespace Folk\Sdk\Grpc;

final class GrpcRouter implements GrpcModeHandler
{
    /** @var array<string, object> service name → handler instance */
    private array $services = [];

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

    public function call(string $service, string $method, string $payload, Context $context): ?string
    {
        $handler = $this->services[$service]
            ?? throw new \RuntimeException("Unknown gRPC service: {$service}");

        if (!method_exists($handler, $method)) {
            throw new \RuntimeException("Unknown method: {$service}/{$method}");
        }

        $ref = new \ReflectionMethod($handler, $method);
        $params = $ref->getParameters();
        $lastParam = $params[count($params) - 1] ?? null;

        if ($lastParam !== null && $this->isProtobufParam($lastParam)) {
            return $this->callTyped($handler, $method, $payload, $params, $context);
        }

        $result = $handler->$method($payload);

        // A business status (setStatus) preempts the response body.
        if ($context->getStatus() !== null) {
            return null;
        }

        return is_string($result) ? $result : null;
    }

    /** @param list<\ReflectionParameter> $params */
    private function callTyped(object $handler, string $method, string $payload, array $params, Context $context): ?string
    {
        $args = [];

        foreach ($params as $param) {
            $type = $param->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : null;

            if ($typeName !== null && is_subclass_of($typeName, \Google\Protobuf\Internal\Message::class)) {
                /** @var \Google\Protobuf\Internal\Message $message */
                $message = new $typeName();
                $message->mergeFromString($payload);
                $args[] = $message;
            } elseif ($typeName === Context::class) {
                $args[] = $context;
            } else {
                $args[] = $context;
            }
        }

        $result = $handler->$method(...$args);

        // A business status (setStatus) preempts the response body; the handler
        // returns null in that case.
        if ($context->getStatus() !== null) {
            return null;
        }

        /** @var \Google\Protobuf\Internal\Message $result */
        return $result->serializeToString();
    }

    private function resolveServiceName(string $nameOrInterface, object $handler): string
    {
        if (defined("{$nameOrInterface}::NAME")) {
            return constant("{$nameOrInterface}::NAME");
        }

        foreach (class_implements($handler) as $interface) {
            if (defined("{$interface}::NAME")) {
                return constant("{$interface}::NAME");
            }
        }

        return $nameOrInterface;
    }

    private function isProtobufParam(\ReflectionParameter $param): bool
    {
        $type = $param->getType();
        if (!$type instanceof \ReflectionNamedType) {
            return false;
        }

        return is_subclass_of($type->getName(), \Google\Protobuf\Internal\Message::class);
    }
}
