<?php declare(strict_types=1);

use Folk\Sdk\Grpc\Hydrator;
use Folk\Sdk\Tests\GrpcFixtures\Color;
use Folk\Sdk\Tests\GrpcFixtures\Everything;
use Folk\Sdk\Tests\GrpcFixtures\Inner;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixtures/dto.php';

final class HydratorTest extends TestCase
{
    private Hydrator $hydrator;

    protected function setUp(): void
    {
        $this->hydrator = new Hydrator();
    }

    /**
     * The core contract: a fully-populated wire message round-trips back to the
     * same array. Bytes decode from base64 into the DTO and re-encode; enums are
     * ints on the wire and cases in the DTO; nested/repeated/map/dynamic survive.
     */
    public function testFullRoundTrip(): void
    {
        $wire = [
            'name' => 'hi',
            'big' => 42,
            'flag' => true,
            'blob' => base64_encode('bytes'),
            'color' => 3,
            'tags' => ['a', 'b'],
            'counts' => ['x' => 1, 'y' => 2],
            'inner' => ['label' => 'L'],
            'maybe' => 'present',
            'text' => 'oneofstr',
            'extra' => ['k' => 'v'],
        ];

        $dto = $this->hydrator->hydrate(Everything::class, $wire);

        $this->assertInstanceOf(Everything::class, $dto);
        $this->assertSame('bytes', $dto->getBlob(), 'bytes decoded from base64');
        $this->assertSame(Color::BLUE, $dto->getColor(), 'enum int → case');
        $this->assertInstanceOf(Inner::class, $dto->getInner());
        $this->assertSame('L', $dto->getInner()->getLabel());
        $this->assertSame(['a', 'b'], $dto->getTags());
        $this->assertSame(['x' => 1, 'y' => 2], $dto->getCounts());
        $this->assertSame(['k' => 'v'], $dto->getExtra());
        $this->assertNull($dto->getNumber(), 'inactive oneof branch stays null');

        $this->assertEquals($wire, $this->hydrator->dehydrate($dto), 'dehydrate reproduces the wire');
    }

    public function testUnknownEnumFallsBackToZeroValue(): void
    {
        $dto = $this->hydrator->hydrate(Everything::class, ['color' => 999]);
        $this->assertSame(Color::COLOR_UNSPECIFIED, $dto->getColor(), 'unknown enum → zero, not an exception');
    }

    public function testAbsentFieldsKeepDefaultsAndPresenceOmitsNulls(): void
    {
        $dto = $this->hydrator->hydrate(Everything::class, ['name' => 'x']);

        $this->assertSame('x', $dto->getName());
        $this->assertNull($dto->getMaybe(), 'unset optional stays null');
        $this->assertNull($dto->getInner());
        $this->assertSame([], $dto->getTags());

        $wire = $this->hydrator->dehydrate($dto);
        $this->assertArrayNotHasKey('maybe', $wire, 'null field omitted from the wire');
        $this->assertArrayNotHasKey('inner', $wire);
        $this->assertArrayNotHasKey('number', $wire);
        $this->assertSame('x', $wire['name']);
    }

    public function testOneofActiveBranchOnly(): void
    {
        $dto = $this->hydrator->hydrate(Everything::class, ['number' => 7]);
        $this->assertSame(7, $dto->getNumber());
        $this->assertNull($dto->getText());

        $wire = $this->hydrator->dehydrate($dto);
        $this->assertSame(7, $wire['number']);
        $this->assertArrayNotHasKey('text', $wire, 'inactive oneof branch absent on the wire');
    }

    public function testBytesBase64Boundary(): void
    {
        $raw = "\x00\x01\x02\xff";
        $dto = $this->hydrator->hydrate(Everything::class, ['blob' => base64_encode($raw)]);
        $this->assertSame($raw, $dto->getBlob(), 'binary bytes decode losslessly');
        $this->assertSame(base64_encode($raw), $this->hydrator->dehydrate($dto)['blob']);
    }

    public function testRepeatedAndMapRoundTrip(): void
    {
        $dto = $this->hydrator->hydrate(Everything::class, [
            'tags' => ['one', 'two', 'three'],
            'counts' => ['a' => 10, 'b' => 20],
        ]);
        $this->assertSame(['one', 'two', 'three'], $dto->getTags());
        $this->assertSame(['a' => 10, 'b' => 20], $dto->getCounts());

        $wire = $this->hydrator->dehydrate($dto);
        $this->assertSame(['one', 'two', 'three'], $wire['tags']);
        $this->assertSame(['a' => 10, 'b' => 20], $wire['counts']);
    }

    public function testClassWithoutFolkFieldsHydratesEmpty(): void
    {
        $dto = $this->hydrator->hydrate(Inner::class, ['label' => 'kept']);
        $this->assertSame('kept', $dto->getLabel());
    }
}
