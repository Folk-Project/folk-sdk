<?php

declare(strict_types=1);

namespace Folk\Sdk\Http;

use Folk\Sdk\Folk;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;

/**
 * Base class for PSR-7 framework integration (Spiral, Symfony, Yii3, Slim).
 *
 * Converts Folk's internal HttpRequest/HttpResponse to PSR-7 and back, including
 * request body streaming (Phase 70/71): on a streamed path the body is pulled
 * from Folk and turned into PSR-7 uploaded files (multipart) or a body stream
 * (raw). Responses whose body size is unknown are streamed back to the client
 * chunk by chunk via Folk's streaming primitives.
 *
 * Requires psr/http-factory; multipart uploads additionally require a
 * UploadedFileFactoryInterface.
 */
abstract class PsrHttpHandler implements HttpModeHandler
{
    private const CHUNK = 65536;

    public function __construct(
        private readonly ServerRequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly ?UploadedFileFactoryInterface $uploadedFileFactory = null,
        /** Default streamed-body size limit in bytes (0 = unlimited). */
        private readonly int $maxRequestBytes = 0,
        /** @var array<string, int> Per-path streamed-body limits. */
        private readonly array $pathLimits = [],
    ) {}

    final public function handle(HttpRequest $request): HttpResponse
    {
        $streamed = null;
        try {
            $psrRequest = $this->folkToPsr($request, $streamed);
            $psrResponse = $this->handlePsr($psrRequest);
            return $this->psrToFolk($psrResponse);
        } catch (StreamLimitExceededException $e) {
            return new HttpResponse(
                status: 413,
                headers: ['Content-Type' => 'application/json'],
                body: json_encode(['error' => $e->getMessage()]) ?: '{}',
            );
        } finally {
            $streamed?->cleanup();
        }
    }

    abstract protected function handlePsr(ServerRequestInterface $request): ResponseInterface;

    /**
     * @param StreamedBody|null $streamed Set to the StreamedBody used (so the
     *                                    caller can clean up temp files).
     */
    private function folkToPsr(HttpRequest $request, ?StreamedBody &$streamed): ServerRequestInterface
    {
        $psrRequest = $this->requestFactory->createServerRequest($request->method, $request->uri);

        foreach ($request->headers as $name => $value) {
            $psrRequest = $psrRequest->withHeader($name, $value);
        }

        if ($request->multipart) {
            $streamed = new StreamedBody(
                StreamedBody::resolveLimit($request->uri, $this->maxRequestBytes, $this->pathLimits),
            );
            $streamed->drainMultipart();
            if ($streamed->post !== []) {
                $psrRequest = $psrRequest->withParsedBody($streamed->post);
            }
            $files = $this->buildUploadedFiles($streamed);
            if ($files !== []) {
                $psrRequest = $psrRequest->withUploadedFiles($files);
            }
        } elseif ($request->bodyStream) {
            $streamed = new StreamedBody(
                StreamedBody::resolveLimit($request->uri, $this->maxRequestBytes, $this->pathLimits),
            );
            $psrRequest = $psrRequest->withBody($this->streamFactory->createStream($streamed->readRaw()));
        } elseif ($request->body !== '') {
            $psrRequest = $psrRequest->withBody($this->streamFactory->createStream($request->body));
        }

        return $psrRequest;
    }

    /**
     * Build PSR-7 uploaded files from spooled multipart parts. Returns [] when
     * no UploadedFileFactory is available (multipart degrades gracefully).
     *
     * @return array<string, \Psr\Http\Message\UploadedFileInterface>
     */
    private function buildUploadedFiles(StreamedBody $streamed): array
    {
        if ($this->uploadedFileFactory === null) {
            return [];
        }
        $files = [];
        foreach ($streamed->files as $file) {
            if ($file->field === null) {
                continue;
            }
            $stream = $this->streamFactory->createStreamFromFile($file->tmpPath, 'rb');
            $files[$file->field] = $this->uploadedFileFactory->createUploadedFile(
                $stream,
                $file->size,
                \UPLOAD_ERR_OK,
                $file->originalName,
                $file->contentType,
            );
        }
        return $files;
    }

    private function psrToFolk(ResponseInterface $response): HttpResponse
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        // Stream the response when the body has no known size (a lazy/streamed
        // body) or the app explicitly opts in with `X-Folk-Stream: yes` — PSR-7
        // has no StreamedResponse class. Otherwise buffer (keeps response.after
        // Lua hooks working).
        if ($body->getSize() === null || strcasecmp($response->getHeaderLine('X-Folk-Stream'), 'yes') === 0) {
            Folk::writeHead($response->getStatusCode(), $headers);
            while (!$body->eof()) {
                $chunk = $body->read(self::CHUNK);
                if ($chunk !== '') {
                    Folk::write($chunk);
                }
            }
            Folk::end();
            return HttpResponse::alreadyStreamed();
        }

        return new HttpResponse(
            status: $response->getStatusCode(),
            headers: $headers,
            body: $body->getContents(),
        );
    }
}
