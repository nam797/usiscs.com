<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Import;

use App\Catalog\Import\InvalidProductRow;
use App\Catalog\Import\ProductRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProductRowTest extends TestCase
{
    private const REQUIRED = ['name' => 'Gloves', 'category' => 'Safety & PPE', 'price' => '9'];

    /** @return array<string, string> */
    private static function minimal(): array
    {
        return ['sku' => 'HB-500', 'name' => 'Hex bolt', 'category' => 'Fasteners & Hardware', 'price' => '12.99'];
    }

    public function testAMinimalRowIsValid(): void
    {
        $row = ProductRow::fromArray(self::minimal());

        self::assertSame('HB-500', $row->sku);
        self::assertSame('Hex bolt', $row->name);
        self::assertSame('fasteners_hardware', $row->categoryCode);
        self::assertSame(1299, $row->priceInCents);
        self::assertNull($row->description);
        self::assertNull($row->stockQty);
        self::assertNull($row->imageUrl);
        self::assertFalse($row->goLive);
    }

    public function testEveryOptionalColumnIsRead(): void
    {
        $row = ProductRow::fromArray(self::minimal() + [
            'description' => 'Grade 8, zinc plated.',
            'short_description' => 'Grade 8 hex bolt',
            'manufacturer' => 'Acme',
            'part_number' => 'AC-1/2-13',
            'unit_of_measure' => 'box of 50',
            'stock_qty' => '120',
            'image_url' => 'https://cdn.example.com/hb500.jpg',
            'go_live' => 'Yes',
        ]);

        self::assertSame('Grade 8, zinc plated.', $row->description);
        self::assertSame('Grade 8 hex bolt', $row->shortDescription);
        self::assertSame('Acme', $row->manufacturer);
        self::assertSame('AC-1/2-13', $row->partNumber);
        self::assertSame('50 EA = 1 BX', $row->unitOfMeasure?->toString());
        self::assertSame(120, $row->stockQty);
        self::assertSame('https://cdn.example.com/hb500.jpg', $row->imageUrl);
        self::assertTrue($row->goLive);
    }

    public function testSupplierSkusWithDotsAndSlashesGetAValidProductCode(): void
    {
        $row = ProductRow::fromArray(['sku' => '012-92-500-7.5-8', 'name' => 'Gloves', 'category' => 'Safety & PPE', 'price' => '9']);

        self::assertSame('012-92-500-7_5-8', $row->sku);
        self::assertSame('012-92-500-7.5-8', $row->supplierSku);
        self::assertSame('112-34-844_XL', ProductRow::fromArray(['sku' => '112-34-844/XL'] + self::REQUIRED)->sku);
        self::assertSame('142-TR-300N_HIK', ProductRow::fromArray(['sku' => '142-TR-300N+HIK'] + self::REQUIRED)->sku);
    }

    public function testItTrimsCellsAndTreatsBlankOptionalsAsNoValue(): void
    {
        $row = ProductRow::fromArray([
            'sku' => '  HB-500 ',
            'name' => ' Hex bolt ',
            'category' => ' fasteners_hardware ',
            'price' => ' 12.99 ',
            'description' => '   ',
        ]);

        self::assertSame('HB-500', $row->sku);
        self::assertSame('Hex bolt', $row->name);
        self::assertNull($row->description);
    }

    public function testItKnowsWhichOptionalColumnsTheSourceHad(): void
    {
        $row = ProductRow::fromArray(self::minimal() + ['description' => '', 'stock_qty' => '5']);

        self::assertTrue($row->provides('description'), 'A blank but present column is provided.');
        self::assertTrue($row->provides('stock_qty'));
        self::assertFalse($row->provides('manufacturer'), 'A column missing from the sheet is not.');
    }

    /** @return iterable<string, array{string, string}> */
    public static function categories(): iterable
    {
        yield 'name' => ['Safety & PPE', 'safety_ppe'];
        yield 'name, any case' => ['SAFETY & ppe', 'safety_ppe'];
        yield 'code' => ['safety_ppe', 'safety_ppe'];
        yield 'code, any case' => ['MRO_MATERIALS', 'mro_materials'];
        yield 'hyphenated name' => ['Specialty & Hard-to-Find', 'specialty_hard_to_find'];
    }

    #[DataProvider('categories')]
    public function testCategoryAcceptsANameOrCode(string $value, string $code): void
    {
        $values = self::minimal();
        $values['category'] = $value;

        self::assertSame($code, ProductRow::fromArray($values)->categoryCode);
    }

    /** @return iterable<string, array{string, int}> */
    public static function prices(): iterable
    {
        yield 'decimal' => ['12.99', 1299];
        yield 'whole dollars' => ['12', 1200];
        yield 'one decimal' => ['12.5', 1250];
        yield 'dollar sign' => ['$12.99', 1299];
        yield 'thousands separator' => ['1,299.00', 129900];
        yield 'both' => ['$ 1,299.95', 129995];
        yield 'cents only' => ['0.99', 99];
    }

    #[DataProvider('prices')]
    public function testPricesAreParsedToCents(string $price, int $cents): void
    {
        $values = self::minimal();
        $values['price'] = $price;

        self::assertSame($cents, ProductRow::fromArray($values)->priceInCents);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidValues(): iterable
    {
        yield 'missing sku' => ['sku', '', 'sku is required'];
        yield 'sku with spaces' => ['sku', 'HB 500', 'may only contain'];
        yield 'sku with a comma' => ['sku', 'HB,500', 'may only contain'];
        yield 'unreadable unit of measure' => ['unit_of_measure', 'sold by the case', 'unit_of_measure: "sold by the case" is not a unit of measure'];
        yield 'missing name' => ['name', '', 'name is required'];
        yield 'long name' => ['name', str_repeat('a', 256), 'longer than 255'];
        yield 'missing category' => ['category', '', 'category is required'];
        yield 'unknown category' => ['category', 'Toys', 'unknown category "Toys"'];
        yield 'missing price' => ['price', '', 'price is required'];
        yield 'zero price' => ['price', '0', 'greater than 0'];
        yield 'negative price' => ['price', '-5', 'not a valid amount'];
        yield 'price with words' => ['price', 'call us', 'not a valid amount'];
        yield 'three decimals' => ['price', '12.999', 'not a valid amount'];
        yield 'bad thousands separator' => ['price', '12,99', 'not a valid amount'];
        yield 'fractional stock' => ['stock_qty', '2.5', 'whole number'];
        yield 'negative stock' => ['stock_qty', '-1', 'whole number'];
        yield 'ftp image' => ['image_url', 'ftp://example.com/a.jpg', 'http(s) link'];
        yield 'local file image' => ['image_url', 'file:///etc/passwd', 'http(s) link'];
        yield 'not a url' => ['image_url', 'hb500.jpg', 'http(s) link'];
        yield 'go_live maybe' => ['go_live', 'maybe', 'yes or no'];
        yield 'long manufacturer' => ['manufacturer', str_repeat('a', 256), 'manufacturer is longer'];
    }

    #[DataProvider('invalidValues')]
    public function testInvalidValuesAreRejectedWithAClearReason(string $column, string $value, string $reason): void
    {
        $values = self::minimal();
        $values[$column] = $value;

        try {
            ProductRow::fromArray($values);
            self::fail('Expected the row to be rejected.');
        } catch (InvalidProductRow $e) {
            self::assertStringContainsString($reason, $e->getMessage());
        }
    }

    public function testEveryProblemInARowIsReportedAtOnce(): void
    {
        try {
            ProductRow::fromArray(['sku' => '', 'name' => '', 'category' => 'Toys', 'price' => 'free']);
            self::fail('Expected the row to be rejected.');
        } catch (InvalidProductRow $e) {
            self::assertCount(4, $e->problems);
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function goLiveValues(): iterable
    {
        foreach (['yes', 'YES', 'y', 'true', '1'] as $yes) {
            yield $yes => [$yes, true];
        }
        foreach (['', 'no', 'N', 'false', '0'] as $no) {
            yield 'no: "'.$no.'"' => [$no, false];
        }
    }

    #[DataProvider('goLiveValues')]
    public function testGoLiveAcceptsCommonYesNoSpellings(string $value, bool $expected): void
    {
        self::assertSame($expected, ProductRow::fromArray(self::minimal() + ['go_live' => $value])->goLive);
    }
}
