<?php

declare(strict_types=1);

namespace Folk\Sdk\Grpc\Codegen;

/**
 * The PHP typing of one message field: the constructor type declaration, an
 * optional richer phpdoc type (for arrays / maps / containers), and the default
 * value expression used in the promoted constructor.
 */
final readonly class PhpType
{
    public function __construct(
        public string $declared,
        public ?string $doc,
        public string $default,
    ) {}
}
