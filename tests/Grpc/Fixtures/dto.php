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

final class Inner
{
    /** @var array<string, list<mixed>> */
    public const FOLK_FIELDS = ['label' => ['s']];

    public function __construct(private string $label = '') {}

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $value): self
    {
        $this->label = $value;

        return $this;
    }
}

final class Everything
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
        private string $name = '',
        private int $big = 0,
        private bool $flag = false,
        private string $blob = '',
        private Color $color = Color::COLOR_UNSPECIFIED,
        private array $tags = [],
        private array $counts = [],
        private ?Inner $inner = null,
        private ?string $maybe = null,
        private ?string $text = null,
        private ?int $number = null,
        private array $extra = [],
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $value): self
    {
        $this->name = $value;

        return $this;
    }

    public function getBig(): int
    {
        return $this->big;
    }

    public function setBig(int $value): self
    {
        $this->big = $value;

        return $this;
    }

    public function getFlag(): bool
    {
        return $this->flag;
    }

    public function setFlag(bool $value): self
    {
        $this->flag = $value;

        return $this;
    }

    public function getBlob(): string
    {
        return $this->blob;
    }

    public function setBlob(string $value): self
    {
        $this->blob = $value;

        return $this;
    }

    public function getColor(): Color
    {
        return $this->color;
    }

    public function setColor(Color $value): self
    {
        $this->color = $value;

        return $this;
    }

    /** @return list<string> */
    public function getTags(): array
    {
        return $this->tags;
    }

    /** @param list<string> $value */
    public function setTags(array $value): self
    {
        $this->tags = $value;

        return $this;
    }

    /** @return array<string, int> */
    public function getCounts(): array
    {
        return $this->counts;
    }

    /** @param array<string, int> $value */
    public function setCounts(array $value): self
    {
        $this->counts = $value;

        return $this;
    }

    public function getInner(): ?Inner
    {
        return $this->inner;
    }

    public function setInner(?Inner $value): self
    {
        $this->inner = $value;

        return $this;
    }

    public function getMaybe(): ?string
    {
        return $this->maybe;
    }

    public function setMaybe(?string $value): self
    {
        $this->maybe = $value;

        return $this;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function setText(?string $value): self
    {
        $this->text = $value;

        return $this;
    }

    public function getNumber(): ?int
    {
        return $this->number;
    }

    public function setNumber(?int $value): self
    {
        $this->number = $value;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getExtra(): array
    {
        return $this->extra;
    }

    /** @param array<string, mixed> $value */
    public function setExtra(array $value): self
    {
        $this->extra = $value;

        return $this;
    }
}
