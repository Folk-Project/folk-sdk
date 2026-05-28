<?php

declare(strict_types=1);

namespace Folk\Sdk\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Base class for PSR-7 framework integration (Spiral, Symfony, Yii3, Slim).
 *
 * Converts Folk's internal HttpRequest/HttpResponse to PSR-7 and back.
 * Requires psr/http-factory.
 */
abstract class PsrHttpHandler implements HttpModeHandler
{
    public function __construct(
        private readonly ServerRequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {}

    final public function handle(HttpRequest $request): HttpResponse
    {
        $psrRequest = $this->folkToPsr($request);
        $psrResponse = $this->handlePsr($psrRequest);
        return $this->psrToFolk($psrResponse);
    }

    abstract protected function handlePsr(ServerRequestInterface $request): ResponseInterface;

    private function folkToPsr(HttpRequest $request): ServerRequestInterface
    {
        $uri = $request->uri;
        $method = $request->method;

        $psrRequest = $this->requestFactory->createServerRequest($method, $uri);

        foreach ($request->headers as $name => $value) {
            $psrRequest = $psrRequest->withHeader($name, $value);
        }

        if ($request->body !== '') {
            $body = $this->streamFactory->createStream($request->body);
            $psrRequest = $psrRequest->withBody($body);
        }

        return $psrRequest;
    }

    private function psrToFolk(ResponseInterface $response): HttpResponse
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        return new HttpResponse(
            status: $response->getStatusCode(),
            headers: $headers,
            body: (string) $response->getBody(),
        );
    }
}
