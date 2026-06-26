<?php declare(strict_types=1);

namespace {
    /**
     * Drives the mocked folk_* streaming functions for StreamedBodyTest.
     *
     * @phpstan-type PartFixture array{meta: array<string,?string>, data: string}
     */
    final class FolkTestStreams
    {
        /** @var list<array{meta: array<string,?string>, data: string}> */
        public static array $parts = [];
        private static int $idx = -1;
        private static int $off = 0;

        /** @var string raw (non-multipart) body for folk_read */
        public static string $raw = '';
        private static int $rawOff = 0;

        /** @param list<array{meta: array<string,?string>, data: string}> $parts */
        public static function loadParts(array $parts): void
        {
            self::$parts = $parts;
            self::$idx = -1;
            self::$off = 0;
        }

        public static function loadRaw(string $raw): void
        {
            self::$raw = $raw;
            self::$rawOff = 0;
        }

        public static function nextPart(): ?string
        {
            self::$idx++;
            self::$off = 0;
            if (self::$idx >= \count(self::$parts)) {
                return null;
            }
            return json_encode(self::$parts[self::$idx]['meta'], JSON_THROW_ON_ERROR);
        }

        public static function partRead(int $len): string
        {
            if (self::$idx < 0 || self::$idx >= \count(self::$parts)) {
                return '';
            }
            $data = self::$parts[self::$idx]['data'];
            if (self::$off >= \strlen($data)) {
                return '';
            }
            $chunk = substr($data, self::$off, $len);
            self::$off += \strlen($chunk);
            return $chunk;
        }

        public static function read(int $len): string
        {
            if (self::$rawOff >= \strlen(self::$raw)) {
                return '';
            }
            $chunk = substr(self::$raw, self::$rawOff, $len);
            self::$rawOff += \strlen($chunk);
            return $chunk;
        }
    }

    if (!\function_exists('folk_next_part')) {
        function folk_next_part(): ?string { return \FolkTestStreams::nextPart(); }
    }
    if (!\function_exists('folk_part_read')) {
        function folk_part_read(int $length = 8192): string { return \FolkTestStreams::partRead($length); }
    }
    if (!\function_exists('folk_read')) {
        function folk_read(int $length = 8192): string { return \FolkTestStreams::read($length); }
    }
}

namespace Folk\Sdk\Tests {

    use Folk\Sdk\Http\StreamedBody;
    use Folk\Sdk\Http\StreamLimitExceededException;
    use PHPUnit\Framework\TestCase;

    final class StreamedBodyTest extends TestCase
    {
        public function testDrainMultipartMixedFormNameAndFile(): void
        {
            $fileData = str_repeat('A', 200_000); // > one chunk
            \FolkTestStreams::loadParts([
                ['meta' => ['name' => 'name', 'filename' => null, 'content_type' => null], 'data' => 'Alice'],
                ['meta' => ['name' => 'avatar', 'filename' => 'a.png', 'content_type' => 'image/png'], 'data' => $fileData],
            ]);

            $body = new StreamedBody();
            $body->drainMultipart();

            // Text part → post field.
            $this->assertSame(['name' => 'Alice'], $body->post);

            // File part → spooled file.
            $this->assertCount(1, $body->files);
            $file = $body->files[0];
            $this->assertSame('avatar', $file->field);
            $this->assertSame('a.png', $file->originalName);
            $this->assertSame('image/png', $file->contentType);
            $this->assertSame(\strlen($fileData), $file->size);
            $this->assertFileExists($file->tmpPath);
            $this->assertSame($fileData, file_get_contents($file->tmpPath));

            $body->cleanup();
            $this->assertFileDoesNotExist($file->tmpPath);
        }

        public function testCleanupRemovesTempFiles(): void
        {
            \FolkTestStreams::loadParts([
                ['meta' => ['name' => 'f', 'filename' => 'x.bin', 'content_type' => null], 'data' => 'data'],
            ]);
            $body = new StreamedBody();
            $body->drainMultipart();
            $path = $body->files[0]->tmpPath;
            $this->assertFileExists($path);

            $body->cleanup();
            $this->assertFileDoesNotExist($path);

            // Idempotent.
            $body->cleanup();
            $this->assertFileDoesNotExist($path);
        }

        public function testLimitExceededThrowsAndCleansUp(): void
        {
            \FolkTestStreams::loadParts([
                ['meta' => ['name' => 'big', 'filename' => 'big.bin', 'content_type' => null], 'data' => str_repeat('x', 1000)],
            ]);
            $body = new StreamedBody(maxBytes: 500);

            try {
                $body->drainMultipart();
                $this->fail('expected StreamLimitExceededException');
            } catch (StreamLimitExceededException $e) {
                $this->assertStringContainsString('500', $e->getMessage());
            }

            // The partial temp file must still be cleanable.
            $body->cleanup();
            foreach ($body->files as $f) {
                $this->assertFileDoesNotExist($f->tmpPath);
            }
        }

        public function testReadRawConcatenatesChunks(): void
        {
            \FolkTestStreams::loadRaw(str_repeat('Z', 150_000));
            $body = new StreamedBody();
            $this->assertSame(150_000, \strlen($body->readRaw()));
        }

        public function testReadRawEnforcesLimit(): void
        {
            \FolkTestStreams::loadRaw(str_repeat('Z', 1000));
            $body = new StreamedBody(maxBytes: 100);
            $this->expectException(StreamLimitExceededException::class);
            $body->readRaw();
        }
    }
}
