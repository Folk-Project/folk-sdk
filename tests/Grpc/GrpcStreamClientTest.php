<?php

declare(strict_types=1);

namespace Folk\Sdk\Tests\Grpc;

use Folk\Sdk\Grpc\Client\GrpcStreamClient;
use Folk\Sdk\Grpc\Client\StreamTransport;
use Folk\Sdk\Grpc\GrpcException;
use PHPUnit\Framework\TestCase;

/** Minimal generated-style DTO (FOLK_FIELDS + promoted ctor). */
final class StreamMsg
{
    public const FOLK_FIELDS = ['seq' => ['d'], 'text' => ['s']];

    public function __construct(
        public int $seq = 0,
        public string $text = '',
    ) {}
}

/** Concrete stub exposing the protected streaming helpers for the test. */
final class StreamTestClient extends GrpcStreamClient
{
    public const CLIENT = 'watcher';
    public const SERVICE = 'folk.test.stream.Watcher';

    /** @return \Generator<int, StreamMsg> */
    public function watch(StreamMsg $request): \Generator
    {
        return $this->serverStream('Watch', $request, StreamMsg::class);
    }

    /** @param iterable<StreamMsg> $requests */
    public function upload(iterable $requests): StreamMsg
    {
        return $this->clientStream('Upload', $requests, StreamMsg::class);
    }

    /**
     * @param iterable<StreamMsg> $requests
     * @return \Generator<int, StreamMsg>
     */
    public function chat(iterable $requests): \Generator
    {
        return $this->bidiStream('Chat', $requests, StreamMsg::class);
    }

    /**
     * @param array<string, mixed> $request
     * @return \Generator<int, array<string, mixed>>
     */
    public function watchArray(array $request): \Generator
    {
        return $this->serverStreamArray('Watch', $request);
    }
}

/** Scriptable {@see StreamTransport} — no extension needed. */
final class FakeStreamTransport implements StreamTransport
{
    /** @var list<array{string, string}> */
    public array $opened = [];
    /** @var list<array{int, string}> */
    public array $sent = [];
    /** @var list<int> */
    public array $closeSends = [];
    /** @var list<int> */
    public array $closes = [];

    /** @var list<?string> inbound frames handed out by recv(), in order */
    private array $recvQueue;

    private int $nextHandle = 1;

    /** @param list<?string> $recvQueue */
    public function __construct(array $recvQueue = [])
    {
        $this->recvQueue = $recvQueue;
    }

    public function open(string $method, string $payload): int
    {
        $this->opened[] = [$method, $payload];
        return $this->nextHandle++;
    }

    public function recv(int $handle): ?string
    {
        return array_shift($this->recvQueue);
    }

    public function send(int $handle, string $payload): void
    {
        $this->sent[] = [$handle, $payload];
    }

    public function closeSend(int $handle): void
    {
        $this->closeSends[] = $handle;
    }

    public function close(int $handle): bool
    {
        $this->closes[] = $handle;
        return true;
    }

    /** The single open()'s decoded envelope. */
    public function envelope(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($this->opened[0][1], true, 512, JSON_THROW_ON_ERROR);
        return $decoded;
    }
}

final class GrpcStreamClientTest extends TestCase
{
    private static function msgFrame(int $seq, string $text): string
    {
        return json_encode(['message' => ['seq' => $seq, 'text' => $text]], JSON_THROW_ON_ERROR);
    }

    private static function statusFrame(int $code, string $message): string
    {
        return json_encode(['__grpc_status' => $code, '__grpc_message' => $message], JSON_THROW_ON_ERROR);
    }

    private function client(FakeStreamTransport $t): StreamTestClient
    {
        return new StreamTestClient(null, null, new \Folk\Sdk\Grpc\Hydrator(), $t);
    }

    public function testServerStreamDrainsHydratedMessagesThenCloses(): void
    {
        $t = new FakeStreamTransport([
            self::msgFrame(0, 'a'),
            self::msgFrame(1, 'b'),
            null, // OK end-of-stream
        ]);
        $client = $this->client($t);

        $seqs = [];
        $texts = [];
        foreach ($client->watch(new StreamMsg(5, 'prices')) as $msg) {
            self::assertInstanceOf(StreamMsg::class, $msg);
            $seqs[] = $msg->seq;
            $texts[] = $msg->text;
        }

        self::assertSame([0, 1], $seqs, 'each message hydrated in order');
        self::assertSame(['a', 'b'], $texts);

        // Exactly one open with the server-streaming envelope carrying the request.
        self::assertCount(1, $t->opened);
        self::assertSame('grpc.client.stream', $t->opened[0][0]);
        $env = $t->envelope();
        self::assertSame('server_streaming', $env['kind']);
        self::assertSame('watcher', $env['client']);
        self::assertSame('folk.test.stream.Watcher', $env['service']);
        self::assertSame('Watch', $env['method']);
        self::assertSame(['seq' => 5, 'text' => 'prices'], $env['message']);

        // No outbound on server-streaming; the stream is closed after draining.
        self::assertSame([], $t->sent);
        self::assertSame([1], $t->closes, 'handle closed once the stream is drained');
    }

    public function testServerStreamRaisesGrpcExceptionOnBusinessStatus(): void
    {
        $t = new FakeStreamTransport([
            self::msgFrame(0, 'a'),
            self::statusFrame(5, 'gone'), // NOT_FOUND after one message
        ]);
        $client = $this->client($t);

        $seen = [];
        try {
            foreach ($client->watch(new StreamMsg()) as $msg) {
                $seen[] = $msg->text;
            }
            self::fail('expected GrpcException from the trailing status');
        } catch (GrpcException $e) {
            self::assertSame(5, $e->getCode());
            self::assertSame('gone', $e->getMessage());
        }

        self::assertSame(['a'], $seen, 'the message before the status was delivered');
        self::assertSame([1], $t->closes, 'stream closed even when it ends in an error');
    }

    public function testServerStreamCloseOnEarlyBreak(): void
    {
        $t = new FakeStreamTransport([
            self::msgFrame(0, 'a'),
            self::msgFrame(1, 'b'),
            self::msgFrame(2, 'c'),
            null,
        ]);
        $client = $this->client($t);

        foreach ($client->watch(new StreamMsg()) as $msg) {
            break; // client abandons the stream after the first message
        }

        self::assertSame([1], $t->closes, 'breaking out of foreach closes (cancels) the stream');
    }

    public function testClientStreamSendsThenReturnsSingleResponse(): void
    {
        $t = new FakeStreamTransport([self::msgFrame(3, 'summary')]);
        $client = $this->client($t);

        $requests = [new StreamMsg(1, 'x'), new StreamMsg(2, 'y'), new StreamMsg(3, 'z')];
        $response = $client->upload($requests);

        self::assertSame(3, $response->seq);
        self::assertSame('summary', $response->text);

        // Three dehydrated requests sent, then half-close, then one recv.
        self::assertCount(3, $t->sent);
        self::assertSame(['seq' => 1, 'text' => 'x'], json_decode($t->sent[0][1], true));
        self::assertSame([1], $t->closeSends, 'outbound half-closed before reading the response');
        self::assertSame([1], $t->closes);

        $env = $t->envelope();
        self::assertSame('client_streaming', $env['kind']);
        self::assertEquals([], (array) $env['message'], 'client-streaming opens with an empty initial message');
    }

    public function testClientStreamThrowsWhenNoResponse(): void
    {
        $t = new FakeStreamTransport([]); // upstream closed with no response frame
        $client = $this->client($t);

        $this->expectException(GrpcException::class);
        $client->upload([new StreamMsg(1, 'x')]);
    }

    public function testBidiSendsAllThenYieldsResponses(): void
    {
        $t = new FakeStreamTransport([
            self::msgFrame(0, 'e0'),
            self::msgFrame(1, 'e1'),
            null,
        ]);
        $client = $this->client($t);

        $texts = [];
        foreach ($client->chat([new StreamMsg(0, 'e0'), new StreamMsg(1, 'e1')]) as $msg) {
            $texts[] = $msg->text;
        }

        self::assertSame(['e0', 'e1'], $texts);
        self::assertCount(2, $t->sent, 'both outbound messages sent (v1 serialized bidi)');
        self::assertSame([1], $t->closeSends);
        self::assertSame([1], $t->closes);
        self::assertSame('bidi', $t->envelope()['kind']);
    }

    public function testMetadataAndDeadlineAndAddressInEnvelope(): void
    {
        $t = new FakeStreamTransport([null]);
        $client = new StreamTestClient('override:50051', null, new \Folk\Sdk\Grpc\Hydrator(), $t);
        $client = $client->withMetadata('x-tenant', 'acme')->withDeadline(2.5);

        foreach ($client->watch(new StreamMsg()) as $_) {
            // drain (empty)
        }

        $env = $t->envelope();
        self::assertSame(['x-tenant' => ['acme']], $env['metadata']);
        self::assertSame(2.5, $env['deadline_seconds']);
        self::assertSame('override:50051', $env['address']);
    }

    public function testUntypedServerStreamYieldsRawArrays(): void
    {
        $t = new FakeStreamTransport([self::msgFrame(7, 'raw'), null]);
        $client = $this->client($t);

        $out = iterator_to_array($client->watchArray(['topic' => 'x']));

        self::assertSame([['seq' => 7, 'text' => 'raw']], $out, 'array variant yields decoded message arrays');
        self::assertSame([1], $t->closes);
    }
}
