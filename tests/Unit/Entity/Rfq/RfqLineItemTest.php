<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity\Rfq;

use App\Entity\Rfq\RfqLineItem;
use App\Entity\Rfq\RfqRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class RfqLineItemTest extends TestCase
{
    public function testItDefaultsToAQuantityOfOne(): void
    {
        $item = new RfqLineItem();

        self::assertNull($item->getId());
        self::assertSame(1, $item->getQuantity());
        self::assertSame('', $item->getDescription());
        self::assertNull($item->getPartNumber());
        self::assertNull($item->getUnitOfMeasure());
    }

    public function testAccessorsRoundTrip(): void
    {
        $request = new RfqRequest();
        $item = new RfqLineItem();

        $item->setRfqRequest($request);
        $item->setQuantity(12);
        $item->setPartNumber('HB-500');
        $item->setDescription('Hex bolt');
        $item->setUnitOfMeasure('box');

        self::assertSame($request, $item->getRfqRequest());
        self::assertSame(12, $item->getQuantity());
        self::assertSame('HB-500', $item->getPartNumber());
        self::assertSame('Hex bolt', $item->getDescription());
        self::assertSame('box', $item->getUnitOfMeasure());
    }

    public function testItsStringFormShowsQuantityAndDescription(): void
    {
        $item = new RfqLineItem();
        $item->setQuantity(3);
        $item->setDescription('Safety gloves');

        self::assertSame('3x Safety gloves', (string) $item);
    }

    public function testAMinimalItemIsValid(): void
    {
        $item = new RfqLineItem();
        $item->setDescription('Safety gloves');

        self::assertCount(0, Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()->validate($item));
    }

    /** @return iterable<string, array{callable(RfqLineItem): void, string}> */
    public static function invalidItems(): iterable
    {
        yield 'zero quantity' => [static fn (RfqLineItem $i) => $i->setQuantity(0), 'quantity'];
        yield 'negative quantity' => [static fn (RfqLineItem $i) => $i->setQuantity(-2), 'quantity'];
        yield 'blank description' => [static fn (RfqLineItem $i) => $i->setDescription(''), 'description'];
        yield 'description too long' => [static fn (RfqLineItem $i) => $i->setDescription(str_repeat('a', 501)), 'description'];
        yield 'part number too long' => [static fn (RfqLineItem $i) => $i->setPartNumber(str_repeat('a', 101)), 'partNumber'];
        yield 'unit too long' => [static fn (RfqLineItem $i) => $i->setUnitOfMeasure(str_repeat('a', 51)), 'unitOfMeasure'];
    }

    /** @param callable(RfqLineItem): void $breakIt */
    #[DataProvider('invalidItems')]
    public function testInvalidItemsAreRejected(callable $breakIt, string $expectedPath): void
    {
        $item = new RfqLineItem();
        $item->setDescription('Safety gloves');
        $breakIt($item);

        $violations = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()->validate($item);

        self::assertCount(1, $violations);
        self::assertSame($expectedPath, $violations[0]?->getPropertyPath());
    }
}
