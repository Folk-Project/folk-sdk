# folk-sdk

PHP SDK for Folk -- worker dispatch loop, HTTP handler, and request/response types.

**Version:** 0.2.0

## Installation

```bash
composer require folk/sdk
```

Requires the `folk.so` PHP extension (built by folk-ext).

## WorkerLoop

`WorkerLoop` is the PHP-side dispatch loop. It auto-detects the dispatch mode:

| Mode | How it works | Serialization |
|------|-------------|---------------|
| **Direct** (preferred) | Rust calls PHP handler via `call_user_function` with zval arrays | None -- zero-copy |
| **Extension** | folk.so extension bridges Rust and PHP | Minimal |
| **Pipe** (legacy) | stdin/stdout msgpack-RPC | Full encode/decode |

### Direct dispatch (zero-copy)

When running inside `folk.so`, Rust passes request data as native PHP arrays (zvals) directly to the handler function. No JSON or msgpack encode/decode is needed. This is the fastest path.

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

## Key classes

- **`WorkerLoop`** -- main dispatch loop, auto-detects mode, calls registered handlers
- **`HttpRequest`** -- fields: `method`, `uri`, `headers`, `body`
- **`HttpResponse`** -- fields: `status`, `headers`, `body`
- **`HttpModeHandler`** -- interface with `handle(HttpRequest): HttpResponse`

## Requirements

- PHP 8.2+
- `folk.so` extension (ZTS build)

## License

MIT
