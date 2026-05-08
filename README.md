# folk-sdk

PHP SDK for Folk — universal worker entry point, MessagePack-RPC protocol, and handler registration.

> **Status:** in active development. See [folk-spec](https://github.com/Folk-Project/folk-spec) for the roadmap.

## Requirements

- PHP 8.2+
- `ext-msgpack` — MessagePack serialization
- `ext-pcntl` — required for fork runtime mode only

## Installation

```bash
composer require folk/sdk
```

## Quick start

The SDK installs `vendor/bin/folk-worker` as the default worker entry point. For custom handlers, create your own script:

```php
<?php
// worker.php
require __DIR__ . '/vendor/autoload.php';

use Folk\Sdk\Worker\WorkerLoop;
use Folk\Sdk\Http\HttpModeHandler;
use Folk\Sdk\Http\HttpRequest;
use Folk\Sdk\Http\HttpResponse;

class MyHandler implements HttpModeHandler
{
    public function handle(HttpRequest $request): HttpResponse
    {
        $response = new HttpResponse();
        $response->status = 200;
        $response->headers = ['Content-Type' => 'application/json'];
        $response->body = json_encode(['uri' => $request->uri, 'method' => $request->method]);
        return $response;
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

## WorkerLoop

`Folk\Sdk\Worker\WorkerLoop` is the main class. It reads tasks from the Rust server over a length-prefixed MessagePack-RPC channel and dispatches them to registered handlers.

### Methods

**`register(string $method, callable $handler): void`**

Register a raw RPC method handler. The callable receives `mixed $params` and returns `mixed`.

```php
$loop->register('ping', function (mixed $params): string {
    return 'pong';
});
```

**`registerHttpHandler(HttpModeHandler $handler): void`**

Register an HTTP request handler. Automatically binds to the `http.handle` RPC method. The handler receives an `HttpRequest` and returns an `HttpResponse`.

```php
$loop->registerHttpHandler(new MyHttpHandler());
```

**`registerJobsHandler(JobsModeHandler $handler): void`**

Register a background jobs handler. Automatically binds to the `jobs.process` RPC method. The handler receives `mixed $payload` and returns `mixed`.

```php
$loop->registerJobsHandler(new MyJobsHandler());
```

**`registerResetter(object $resetter): void`**

Register an object whose `reset()` method is called after each request. Used to clean up shared state between requests in long-lived workers.

```php
$loop->registerResetter($myStatefulService);
```

**`run(): void`**

Start the worker loop. Blocks until the server signals shutdown. Reads from file descriptors specified by `FOLK_TASK_FD` and `FOLK_CONTROL_FD` environment variables.

## Handler interfaces

**`HttpModeHandler`** — `handle(HttpRequest $request): HttpResponse`

**`JobsModeHandler`** — `process(mixed $payload): mixed`

## Data classes

**`HttpRequest`** (readonly):
- `string $method` — HTTP method (GET, POST, etc.)
- `string $uri` — Request URI with query string
- `array $headers` — Header map (string => string)
- `string $body` — Request body

**`HttpResponse`**:
- `int $status` — HTTP status code (default: 200)
- `array $headers` — Response headers (default: [])
- `string $body` — Response body (default: '')

## How it works

The worker process communicates with the Rust server over two file descriptors:

- **FD 3 (task channel)** — receives RPC requests, sends responses
- **FD 4 (control channel)** — sends `control.ready` at boot, receives shutdown signal

Messages use a length-prefixed MessagePack-RPC wire format:

```
[4-byte big-endian length][MessagePack payload]
```

Message types follow MessagePack-RPC:
- Request: `[0, msgid, method, params]`
- Response: `[1, msgid, error, result]`
- Notify: `[2, method, params]`

On startup, the worker sends a `control.ready` notification with its PID. The server then begins dispatching tasks. After each task, registered resetters run to clean up state.

## License

MIT
