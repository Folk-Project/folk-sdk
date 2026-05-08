<?php

declare(strict_types=1);

namespace Folk\Sdk\Protocol\Exception;

/**
 * Thrown when a protocol-level error occurs (malformed frame, invalid message, etc.).
 */
class ProtocolException extends \RuntimeException
{
}
