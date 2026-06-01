<?php declare(strict_types=1);

use Folk\Sdk\Http\HttpRequest;
use Folk\Sdk\Http\HttpResponse;
use PHPUnit\Framework\TestCase;

final class HttpPayloadTest extends TestCase
{
    public function testFromPayloadUtf8Body(): void
    {
        $request = HttpRequest::fromPayload([
            'method' => 'POST',
            'uri' => '/test',
            'headers' => [],
            'body' => 'hello world',
        ]);
        $this->assertSame('hello world', $request->body);
    }

    public function testFromPayloadBase64Body(): void
    {
        $binary = "\x00\x01\xFF\xFE\x89PNG";
        $request = HttpRequest::fromPayload([
            'method' => 'POST',
            'uri' => '/upload',
            'headers' => ['content-type' => 'application/octet-stream'],
            'body' => base64_encode($binary),
            'body_encoding' => 'base64',
        ]);
        $this->assertSame($binary, $request->body);
    }

    public function testFromPayloadNoEncodingField(): void
    {
        $request = HttpRequest::fromPayload([
            'method' => 'GET',
            'uri' => '/',
            'headers' => [],
        ]);
        $this->assertSame('', $request->body);
    }

    public function testResponseToPayloadUtf8(): void
    {
        $response = new HttpResponse(200, ['content-type' => 'text/plain'], 'hello');
        $payload = $response->toPayload();
        $this->assertSame('hello', $payload['body']);
        $this->assertArrayNotHasKey('body_encoding', $payload);
    }

    public function testResponseToPayloadBinary(): void
    {
        $binary = "\x00\x01\xFF\xFE\x89PNG";
        $response = new HttpResponse(200, ['content-type' => 'application/octet-stream'], $binary);
        $payload = $response->toPayload();
        $this->assertSame('base64', $payload['body_encoding']);
        $this->assertSame($binary, base64_decode($payload['body'], true));
    }
}
