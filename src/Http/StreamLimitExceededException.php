<?php

declare(strict_types=1);

namespace Folk\Sdk\Http;

/**
 * Thrown by {@see StreamedBody} when a streamed request body exceeds the
 * configured byte limit. Framework adapters should map this to HTTP 413
 * (Payload Too Large).
 */
final class StreamLimitExceededException extends \RuntimeException
{
}
