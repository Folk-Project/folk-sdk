# folk-sdk

PHP SDK for Folk — worker entry point, handler registration, RPC client, and protocol implementation.

## Requirements

- PHP 8.2+
- `ext-msgpack` — MessagePack serialization
- `ext-pcntl` — fork runtime mode only
- `ext-sockets` — fork runtime mode only

## Installation

```bash
composer require folk/sdk
```

## Quick start

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Folk\Sdk\Worker\WorkerLoop;
use Folk\Sdk\Http\HttpModeHandler;
use Folk\Sdk\Http\HttpRequest;
use Folk\Sdk\Http\HttpResponse;

class MyHandler implements HttpModeHandler
{
    public function handle(HttpRequest $request): HttpResponse
    {
        return new HttpResponse(
            status: 200,
            headers: ['Content-Type' => 'application/json'],
            body: json_encode(['uri' => $request->uri]),
        );
    }
}

$loop = new WorkerLoop();
$loop->registerHttpHandler(new MyHandler());
$loop->run();
```

Point `folk.toml` at your script:

```toml
[workers]
script = "worker.php"
```

## Handler registration

### HTTP

```php
use Folk\Sdk\Http\HttpModeHandler;
use Folk\Sdk\Http\HttpRequest;
use Folk\Sdk\Http\HttpResponse;

class MyHttpHandler implements HttpModeHandler
{
    public function handle(HttpRequest $request): HttpResponse
    {
        return new HttpResponse(200, [], "Hello from {$request->uri}");
    }
}

$loop->registerHttpHandler(new MyHttpHandler());
```

`HttpRequest` fields: `method`, `uri`, `headers`, `body`
`HttpResponse` fields: `status`, `headers`, `body`

### Jobs

```php
use Folk\Sdk\Jobs\JobsModeHandler;

class MyJobHandler implements JobsModeHandler
{
    public function process(mixed $payload): mixed
    {
        $data = json_decode($payload, true);
        // Process the job...
        return ['status' => 'ok'];
    }
}

$loop->registerJobsHandler(new MyJobHandler());
```

The handler receives the raw payload string that was pushed to the queue. Parse it however you need (JSON, msgpack, etc.).

### gRPC

Two styles available — raw bytes or typed protobuf.

**Raw bytes mode:**

```php
use Folk\Sdk\Grpc\GrpcModeHandler;
use Folk\Sdk\Grpc\Context;

class MyGrpcHandler implements GrpcModeHandler
{
    public function call(string $service, string $method, string $payload, Context $context): string
    {
        $token = $context->getValue('authorization');

        $request = new \MyProto\MyRequest();
        $request->mergeFromString($payload);

        $reply = new \MyProto\MyReply();
        $reply->setResult("processed");

        return $reply->serializeToString();
    }
}

$loop->registerGrpcHandler(new MyGrpcHandler());
```

For typed protobuf mode with auto-detection, see [folk-laravel](https://github.com/Folk-Project/folk-laravel) integration.

### Resetters

Objects whose `reset()` method is called between requests to clean up shared state:

```php
$loop->registerResetter($myDbConnection);
$loop->registerResetter($myAuthState);
```

### Custom RPC methods

```php
$loop->register('my.custom', function (mixed $params): mixed {
    return ['echo' => $params];
});
```

## RPC Client

The SDK includes an RPC client for calling Folk's admin socket:

```php
use Folk\Sdk\Rpc\RpcClient;

$rpc = new RpcClient('./tmp/folk.sock');

// Push a job
$rpc->call('jobs.push', [
    'queue' => 'default',
    'payload' => json_encode(['task' => 'send-email']),
]);

// Get queue stats
$stats = $rpc->call('jobs.stats');
```

The RPC client communicates over a Unix socket using the same MessagePack-RPC protocol as the worker channels. Use it to interact with Folk from any PHP context (CLI scripts, HTTP handlers, cron jobs, etc.).

## Fork mode

For applications with heavy boot (frameworks, many service providers), fork mode boots the framework once in a master process. Workers are forked from the warm master — no cold start per worker.

```toml
# folk.toml
[server]
runtime = "fork"
```

Requirements: `ext-pcntl`, `ext-sockets`.

The SDK automatically detects `FOLK_RUNTIME=fork` and uses `ForkMasterLoop` instead of `WorkerLoop`. The master:

1. Boots the application framework (warm OPcache)
2. Sends `control.fork-ready`
3. Receives socket file descriptors via SCM_RIGHTS for each worker
4. Forks children that enter the standard `WorkerLoop`

## Protocol

Workers communicate with the Rust server over two file descriptors:

- **FD 3 (task channel)** — RPC requests and responses
- **FD 4 (control channel)** — lifecycle signals (`control.ready`, `control.shutdown`)

Wire format: `[4-byte BE length][MessagePack payload]`

Message types (MessagePack-RPC):
- Request: `[0, msgid, method, params]`
- Response: `[1, msgid, error, result]`
- Notify: `[2, method, params]`

## License

MIT
