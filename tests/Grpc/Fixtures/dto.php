<?php

declare(strict_types=1);

namespace Folk\Sdk\Tests\GrpcFixtures;

/**
 * Hand-written stand-ins for generated DTOs, used to exercise the Hydrator and
 * GrpcRouter transcode tier without invoking codegen. The `FOLK_FIELDS` shape
 * matches exactly what {@see \Folk\Sdk\Grpc\Codegen\ProtoGenerator} emits.
 */
enum Color: int
{
    case COLOR_UNSPECIFIED = 0;
    case RED = 1;
    case GREEN = 2;
    case BLUE = 3;
}

final readonly class Inner
{
    /** @var array<string, list<mixed>> */
    public const FOLK_FIELDS = ['label' => ['s']];

    public function __construct(public string $label = '') {}
}

final readonly class Everything
{
    /** @var array<string, list<mixed>> */
    public const FOLK_FIELDS = [
        'name' => ['s'],
        'big' => ['s'],
        'flag' => ['s'],
        'blob' => ['b'],
        'color' => ['e', Color::class],
        'tags' => ['r', ['s']],
        'counts' => ['map', ['s']],
        'inner' => ['m', Inner::class],
        'maybe' => ['s'],
        'text' => ['s'],
        'number' => ['s'],
        'extra' => ['d'],
    ];

    /**
     * @param list<string>        $tags
     * @param array<string, int>  $counts
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public string $name = '',
        public int $big = 0,
        public bool $flag = false,
        public string $blob = '',
        public Color $color = Color::COLOR_UNSPECIFIED,
        public array $tags = [],
        public array $counts = [],
        public ?Inner $inner = null,
        public ?string $maybe = null,
        public ?string $text = null,
        public ?int $number = null,
        public array $extra = [],
    ) {}
}
