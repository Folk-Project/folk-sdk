<?php declare(strict_types=1);

namespace Folk\Sdk\Http;

interface HttpModeHandler
{
    public function handle(HttpRequest $request): HttpResponse;
}
