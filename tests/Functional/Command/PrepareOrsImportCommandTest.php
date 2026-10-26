<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Catalog\Import\ExcelProductSource;
use App\Catalog\Import\ProductImporter;
use App\Tests\Functional\FunctionalTestCase;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The converter against small copies of the two ORS spreadsheets, with the
 * real column headings; its output must import cleanly.
 */
final class PrepareOrsImportCommandTest extends FunctionalTestCase
{
    private const FEED_HEADER = ['ORS SKU', 'USISCS segment', 'Selected product', 'Feed status', 'ORS feed description', 'ORS brand', 'Vendor part number', 'List / standard pack', 'Customer cost / standard pack', 'Cost UOM', 'UOM conversion'];
    private const REVIEW_HEADER = ['ORS SKU', 'Product family', 'Brand', 'USISCS segment', 'Launch tier'];

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/usiscs-ors-prep-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
        parent::tearDown();
    }

    public function testItBuildsAnImportSheetWithGroupsAndSkipsUnpricedSkus(): void
    {
        $tester = $this->runCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $rows = $this->rows('Products');
        self::assertCount(4, $rows);

        $gloves = $rows['PRP-11-600-10-BK'];
        self::assertSame('HyFlex® 11-600 Palm-Coated Gloves', $gloves['name']);
        self::assertSame('hyflex-11-600-palm-coated-gloves', $gloves['group']);
        self::assertSame('Black, Size 10', $gloves['variant_name']);
        self::assertSame('51.85', $gloves['price'], 'ORS list 51.852 rounds to the cent.');
        self::assertSame('12 PR = 1 DZ', $gloves['unit_of_measure']);
        self::assertSame('Safety & PPE', $gloves['category']);
        self::assertSame('no', $gloves['go_live']);
        self::assertSame('White, Size 6', $rows['PRP-11-600-6-WH']['variant_name']);

        $pads = $rows['PRP-1001'];
        self::assertSame('Allegro™ Respirator Cleaning Pads', $pads['name'], 'The sheets show ™ as ¢.');
        self::assertSame('', $pads['group'], 'A family of one is a product of its own.');
        self::assertSame('Allegro', $pads['manufacturer']);
        self::assertSame('1 BX = 1 BX', $pads['unit_of_measure']);

        self::assertSame(['PRP-ABSENT' => 'no ORS list price (Absent from feed)'], array_map(static fn (array $r): string => $r['reason'], $this->rows('Skipped')));
    }

    public function testTheSheetImports(): void
    {
        $this->runCommand();

        $importer = static::getContainer()->get(ProductImporter::class);
        self::assertInstanceOf(ProductImporter::class, $importer);
        $report = $importer->import(new ExcelProductSource($this->dir.'/out.xlsx'), true);

        self::assertSame(0, $report->failed, implode("\n", array_column($report->messages(), 'message')));
        self::assertSame(4, $report->created);
    }

    public function testAMissingSheetIsExplained(): void
    {
        $tester = $this->runCommand(feedSheet: 'Sheet1');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('has no "ORS price feed" sheet', $tester->getDisplay());
    }

    private function runCommand(string $feedSheet = 'ORS price feed'): CommandTester
    {
        $this->sheet('feed.xlsx', $feedSheet, self::FEED_HEADER, [
            ['PRP-1001', 'Safety', 'Allegro¢ Respirator Cleaning Pads [ORS PRP-1001]', 'Exact SKU priced', 'RESPIRATOR CLEANING PADS', 'ALLEGRO', '1001', '17.4', '13.97', 'BX', '1 BX = 1 BX'],
            ['PRP-11-600-10-BK', 'Safety', 'HyFlex® 11-600 [ORS PRP-11-600-10-BK]', 'Exact SKU priced', 'HYFLEX 11-600 BLK LT WGHT ASSEMB PU/COAT SZ 10', 'ANSELL', '11-600-10-BK', '51.852', '40', 'DZ', '12 PR = 1 DZ'],
            ['PRP-11-600-9-BK', 'Safety', 'HyFlex® 11-600 [ORS PRP-11-600-9-BK]', 'Exact SKU priced', 'HYFLEX 11-600 BLK LT WGHT ASSEMB PU/COAT SZ 9', 'ANSELL', '11-600-9-BK', '51.852', '40', 'DZ', '12 PR = 1 DZ'],
            ['PRP-11-600-6-WH', 'Safety', 'HyFlex® 11-600 [ORS PRP-11-600-6-WH]', 'Exact SKU priced', 'HYFLEX 11-600 WHT LT WGHT ASSEMB PU/COAT SZ6', 'ANSELL', '11-600-6-WH', '622.224', '500', 'CA', '144 PR = 1 CA'],
            ['PRP-ABSENT', 'Safety', 'HyFlex® 11-600 [ORS PRP-ABSENT]', 'Absent from feed', '', '', '', '', '', '', ''],
        ]);
        $this->sheet('review.xlsx', 'SKU review', self::REVIEW_HEADER, [
            ['PRP-1001', 'Allegro¢ Respirator Cleaning Pads', 'Allegro', 'Safety', 'Core 2,000'],
            ['PRP-11-600-10-BK', 'HyFlex® 11-600 Palm-Coated Gloves', 'HyFlex®', 'Safety', 'Core 2,000'],
            ['PRP-11-600-9-BK', 'HyFlex® 11-600 Palm-Coated Gloves', 'HyFlex®', 'Safety', 'Core 2,000'],
            ['PRP-11-600-6-WH', 'HyFlex® 11-600 Palm-Coated Gloves', 'HyFlex®', 'Safety', 'Core 2,000'],
            ['PRP-ABSENT', 'HyFlex® 11-600 Palm-Coated Gloves', 'HyFlex®', 'Safety', 'Core 2,000'],
        ]);

        $kernel = static::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('app:catalog:prepare-ors-import'));
        $tester->execute(['feed' => $this->dir.'/feed.xlsx', 'out' => $this->dir.'/out.xlsx', '--families' => $this->dir.'/review.xlsx']);

        return $tester;
    }

    /**
     * @param list<string>       $header
     * @param list<list<string>> $rows
     */
    private function sheet(string $file, string $sheetName, array $header, array $rows): void
    {
        $writer = new Writer();
        $writer->openToFile($this->dir.'/'.$file);
        $writer->getCurrentSheet()->setName($sheetName);
        $writer->addRow(Row::fromValues($header));
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();
    }

    /** @return array<string, array<string, string>> keyed by sku */
    private function rows(string $sheetName): array
    {
        $reader = new Reader();
        $reader->open($this->dir.'/out.xlsx');
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            if ($sheet->getName() !== $sheetName) {
                continue;
            }
            $header = null;
            foreach ($sheet->getRowIterator() as $row) {
                $cells = array_map(static fn (mixed $c): string => is_scalar($c) ? (string) $c : '', $row->toArray());
                if (null === $header) {
                    $header = $cells;
                    continue;
                }
                $values = array_combine($header, array_pad($cells, count($header), ''));
                $rows[$values['sku']] = $values;
            }
        }
        $reader->close();

        return $rows;
    }
}
