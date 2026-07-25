<?php

declare(strict_types=1);

namespace Folk\Sdk\Grpc\Codegen;

/**
 * Minimal protobuf wire-format reader — just enough to walk a `FileDescriptorSet`
 * (descriptor.proto) in pure PHP, so code generation needs neither protoc nor the
 * protobuf extension. Only the wire types that appear in descriptors are handled;
 * everything else is skipped.
 *
 * @internal
 */
final class ProtoReader
{
    private int $pos = 0;
    private readonly int $len;

    public function __construct(private readonly string $buf)
    {
        $this->len = strlen($buf);
    }

    public function eof(): bool
    {
        return $this->pos >= $this->len;
    }

    /** Read a base-128 varint (descriptors only carry small values). */
    public function readVarint(): int
    {
        $result = 0;
        $shift = 0;
        while (true) {
            if ($this->pos >= $this->len) {
                throw new \RuntimeException('protobuf: unexpected EOF reading varint');
            }
            $byte = ord($this->buf[$this->pos]);
            $this->pos++;
            $result |= ($byte & 0x7f) << $shift;
            if (($byte & 0x80) === 0) {
                break;
            }
            $shift += 7;
        }
        return $result;
    }

    /**
     * Read a field tag into `[fieldNumber, wireType]`.
     *
     * @return array{int, int}
     */
    public function readTag(): array
    {
        $tag = $this->readVarint();
        return [$tag >> 3, $tag & 0x7];
    }

    /** Read a length-delimited chunk (wire type 2): string, bytes, or sub-message. */
    public function readLengthDelimited(): string
    {
        $len = $this->readVarint();
        if ($this->pos + $len > $this->len) {
            throw new \RuntimeException('protobuf: length-delimited field exceeds buffer');
        }
        $chunk = substr($this->buf, $this->pos, $len);
        $this->pos += $len;
        return $chunk;
    }

    /** Skip a field of the given wire type. */
    public function skip(int $wireType): void
    {
        switch ($wireType) {
            case 0: // varint
                $this->readVarint();
                break;
            case 1: // 64-bit
                $this->pos += 8;
                break;
            case 2: // length-delimited
                $this->pos += $this->readVarint();
                break;
            case 5: // 32-bit
                $this->pos += 4;
                break;
            default:
                throw new \RuntimeException("protobuf: unsupported wire type {$wireType}");
        }
    }
}
