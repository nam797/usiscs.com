<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Import;

use App\Catalog\Import\ExcelProductSource;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use PHPUnit\Framework\TestCase;

final class ExcelProductSourceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/usiscs-import-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    public function testItReadsAnXlsxSheetByHeaderName(): void
    {
        $path = $this->xlsx([
            ['Price', 'SKU', 'Name', 'Category'],
            [12.99, 'HB-500', 'Hex bolt', 'Fasteners & Hardware'],
        ]);

        self::assertSame(
            [2 => ['price' => '12.99', 'sku' => 'HB-500', 'name' => 'Hex bolt', 'category' => 'Fasteners & Hardware']],
            $this->read($path),
        );
    }

    public function testHeaderNamesAreNormalised(): void
    {
        $path = $this->xlsx([
            ['sku', 'name', 'category', 'price', ' Short Description ', 'Unit-Of-Measure', 'GO LIVE'],
            ['A1', 'Gloves', 'safety_ppe', '9', 'Nitrile', 'pair', 'yes'],
        ]);

        $row = $this->read($path)[2];
        self::assertSame('Nitrile', $row['short_description']);
        self::assertSame('pair', $row['unit_of_measure']);
        self::assertSame('yes', $row['go_live']);
    }

    public function testNumbersBooleansAndDatesBecomeTheTextAPersonWouldType(): void
    {
        $path = $this->xlsx([
            ['sku', 'name', 'category', 'price', 'stock_qty', 'go_live', 'description'],
            [1001, 'Gloves', 'safety_ppe', 12.5, 40, true, Cell::fromValue(new \DateTimeImmutable('2026-11-01'), (new Style())->withFormat('yyyy-mm-dd'))],
        ]);

        $row = $this->read($path)[2];
        self::assertSame('1001', $row['sku']);
        self::assertSame('12.5', $row['price']);
        self::assertSame('40', $row['stock_qty']);
        self::assertSame('yes', $row['go_live']);
        self::assertSame('2026-11-01', $row['description']);
    }

    public function testAFloatPriceNeverPicksUpRoundingNoise(): void
    {
        $path = $this->xlsx([['sku', 'name', 'category', 'price'], ['A', 'B', 'mro_materials', 0.1 + 0.2]]);

        self::assertSame('0.3', $this->read($path)[2]['price']);
    }

    public function testBlankLinesAreSkippedButRowNumbersMatchTheSheet(): void
    {
        $path = $this->xlsx([
            ['sku', 'name', 'category', 'price'],
            ['A', 'First', 'mro_materials', '1'],
            ['', '', '', ''],
            ['B', 'Second', 'mro_materials', '2'],
        ]);

        self::assertSame([2, 4], array_keys($this->read($path)));
    }

    public function testShortRowsGetBlankValuesForMissingCells(): void
    {
        $path = $this->xlsx([
            ['sku', 'name', 'category', 'price', 'description'],
            ['A', 'First', 'mro_materials', '1'],
        ]);

        self::assertSame('', $this->read($path)[2]['description']);
    }

    public function testItReadsCsvToo(): void
    {
        $path = $this->dir.'/products.csv';
        $writer = new CsvWriter();
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['sku', 'name', 'category', 'price']));
        $writer->addRow(Row::fromValues(['HB-500', 'Hex bolt, grade 8', 'fasteners_hardware', '1,299.00']));
        $writer->close();

        self::assertSame(
            [2 => ['sku' => 'HB-500', 'name' => 'Hex bolt, grade 8', 'category' => 'fasteners_hardware', 'price' => '1,299.00']],
            $this->read($path),
        );
    }

    public function testAMissingRequiredColumnStopsTheImportWithAClearMessage(): void
    {
        $path = $this->xlsx([['sku', 'name', 'cost'], ['A', 'B', '1']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing required column(s): category, price');

        $this->read($path);
    }

    public function testAnUnsupportedFileTypeIsRejected(): void
    {
        $path = $this->dir.'/products.xls';
        touch($path);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('use .xlsx or .csv');

        $this->read($path);
    }

    public function testAMissingFileIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot read');

        $this->read($this->dir.'/nope.xlsx');
    }

    public function testItIsNamedExcel(): void
    {
        self::assertSame('excel', (new ExcelProductSource('x.xlsx'))->name());
    }

    /** @return array<int, array<string, string>> */
    private function read(string $path): array
    {
        $rows = [];
        foreach ((new ExcelProductSource($path))->rows() as $number => $values) {
            $rows[$number] = $values;
        }

        return $rows;
    }

    /** @param list<list<Cell|bool|\DateTimeInterface|float|int|string|null>> $rows */
    private function xlsx(array $rows): string
    {
        $path = $this->dir.'/products.xlsx';
        $writer = new XlsxWriter();
        $writer->openToFile($path);
        foreach ($rows as $values) {
            $cells = array_map(static fn (Cell|bool|\DateTimeInterface|float|int|string|null $v): Cell => $v instanceof Cell ? $v : Cell::fromValue($v), $values);
            $writer->addRow(new Row($cells));
        }
        $writer->close();

        return $path;
    }
}
