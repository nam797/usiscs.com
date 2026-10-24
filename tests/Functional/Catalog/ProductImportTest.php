<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Catalog\Import\ExcelProductSource;
use App\Catalog\Import\ImportReport;
use App\Catalog\Import\ProductImporter;
use App\Entity\Catalog\ProductImportState;
use App\Entity\Product\Product;
use App\Tests\Functional\FunctionalTestCase;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Runs the real importer against the test database. Every SKU used here
 * starts with "IMP-" and is removed before each test.
 */
final class ProductImportTest extends FunctionalTestCase
{
    private const HEADER = ['sku', 'name', 'category', 'price', 'description', 'manufacturer', 'stock_qty', 'go_live'];

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/usiscs-import-e2e-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->removeImportedProducts();
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
        parent::tearDown();
    }

    public function testNewProductsArriveAsHiddenDraftsWithEverything(): void
    {
        $report = $this->import([
            ['IMP-HB-500', 'Hex bolt', 'Fasteners & Hardware', '12.99', 'Grade 8.', 'Acme', '120', ''],
        ]);

        self::assertSame(1, $report->created);
        $product = $this->product('IMP-HB-500');
        self::assertFalse($product->isEnabled(), 'New products are hidden until someone takes them live.');
        self::assertSame('Hex bolt', $product->getName());
        self::assertSame('hex-bolt', $product->getSlug());
        self::assertSame('Grade 8.', $product->getDescription());
        self::assertSame('fasteners_hardware', $product->getMainTaxon()?->getCode());
        self::assertSame('Acme', $product->getAttributeByCodeAndLocale('manufacturer', 'en_US')?->getValue());
        self::assertSame(['default'], array_map(static fn ($c) => $c->getCode(), $product->getChannels()->toArray()));

        $variant = $this->variant($product);
        self::assertSame('IMP-HB-500', $variant->getCode());
        self::assertSame(1299, $this->price($product));
        self::assertTrue($variant->isTracked());
        self::assertSame(120, $variant->getOnHand());
    }

    public function testGoLiveYesPublishesAReadyProduct(): void
    {
        $report = $this->import([['IMP-GL-1', 'Gloves', 'Safety & PPE', '9.50', '', '', '', 'yes']]);

        self::assertSame(1, $report->createdLive);
        self::assertTrue($this->product('IMP-GL-1')->isEnabled());

        $this->client->request('GET', '/products/gloves');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Gloves');
    }

    public function testDraftsStayOffTheStorefrontAndSitemap(): void
    {
        $this->import([['IMP-DR-1', 'Draft drill', 'MRO Materials', '99', '', '', '', 'no']]);

        $this->client->request('GET', '/products/draft-drill');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/sitemap.xml');
        self::assertStringNotContainsString('draft-drill', (string) $this->client->getResponse()->getContent());
    }

    public function testAReImportUpdatesFieldsNobodyEdited(): void
    {
        $this->import([['IMP-HB-500', 'Hex bolt', 'Fasteners & Hardware', '12.99', '', 'Acme', '120', '']]);

        $report = $this->import([['IMP-HB-500', 'Hex bolt', 'Fasteners & Hardware', '13.49', '', 'Acme Corp', '80', '']]);

        self::assertSame(1, $report->updated);
        $product = $this->product('IMP-HB-500');
        self::assertSame(1349, $this->price($product));
        self::assertSame(80, $this->variant($product)->getOnHand());
        self::assertSame('Acme Corp', $product->getAttributeByCodeAndLocale('manufacturer', 'en_US')?->getValue());
    }

    public function testAnAdminsEditSurvivesEveryReImport(): void
    {
        $this->import([['IMP-HB-500', 'Hex bolt', 'Fasteners & Hardware', '12.99', '', '', '', '']]);

        // An admin renames it and changes the price in the Sylius admin.
        $product = $this->product('IMP-HB-500');
        $product->setName('Hex Bolt 1/2" Grade 8');
        $this->pricing($product)->setPrice(1500);
        $this->entityManager()->flush();

        $report = $this->import([['IMP-HB-500', 'HEX BOLT GR8', 'Fasteners & Hardware', '13.49', 'New text', '', '', '']]);

        $product = $this->product('IMP-HB-500');
        self::assertSame('Hex Bolt 1/2" Grade 8', $product->getName(), 'The admin name is kept.');
        self::assertSame(1500, $this->price($product), 'The admin price is kept.');
        self::assertSame('New text', $product->getDescription(), 'Untouched fields still update.');
        self::assertStringContainsString('kept admin edits to name, price', implode("\n", array_column($report->messages(), 'message')));

        // And again on the next import.
        $this->import([['IMP-HB-500', 'HEX BOLT GR8 v2', 'Fasteners & Hardware', '14.00', 'New text', '', '', '']]);
        self::assertSame('Hex Bolt 1/2" Grade 8', $this->product('IMP-HB-500')->getName());
    }

    public function testAReImportNeverChangesWhetherAProductIsLive(): void
    {
        $this->import([['IMP-GL-1', 'Gloves', 'Safety & PPE', '9.50', '', '', '', 'yes']]);
        $product = $this->product('IMP-GL-1');
        $product->setEnabled(false); // taken offline by an admin
        $this->entityManager()->flush();

        $this->import([['IMP-GL-1', 'Gloves', 'Safety & PPE', '9.75', '', '', '', 'yes']]);

        self::assertFalse($this->product('IMP-GL-1')->isEnabled());
    }

    public function testChangingTheCategoryMovesTheProduct(): void
    {
        $this->import([['IMP-MV-1', 'Pump', 'MRO Materials', '250', '', '', '', '']]);
        $this->import([['IMP-MV-1', 'Pump', 'Maintenance Supplies', '250', '', '', '', '']]);

        $product = $this->product('IMP-MV-1');
        self::assertSame('maintenance_supplies', $product->getMainTaxon()?->getCode());
        self::assertSame(['maintenance_supplies'], array_values(array_map(static fn ($pt) => $pt->getTaxon()?->getCode(), $product->getProductTaxons()->toArray())));
    }

    public function testAProductMadeByHandWithTheSameSkuIsLeftAlone(): void
    {
        $this->ensureProduct('IMP-HAND-1', 'Hand made', 'hand-made', 'mro_materials');

        $report = $this->import([['IMP-HAND-1', 'Imported name', 'MRO Materials', '1', '', '', '', '']]);

        self::assertSame(1, $report->untouched);
        self::assertSame('Hand made', $this->product('IMP-HAND-1')->getName());
    }

    public function testBadRowsAreReportedAndTheRestStillImport(): void
    {
        $report = $this->import([
            ['IMP-OK-1', 'Good one', 'MRO Materials', '1.00', '', '', '', ''],
            ['IMP-BAD-1', 'Bad price', 'MRO Materials', 'call us', '', '', '', ''],
            ['IMP-BAD-2', 'Bad category', 'Toys', '1', '', '', '', ''],
            ['IMP-OK-1', 'Duplicate', 'MRO Materials', '1', '', '', '', ''],
            ['IMP-OK-2', 'Good two', 'MRO Materials', '2', '', '', '', ''],
        ]);

        self::assertSame(2, $report->created);
        self::assertSame(3, $report->failed);
        $errors = $report->messages(ImportReport::ERROR);
        self::assertSame([3, 4, 5], array_column($errors, 'row'), 'Row numbers match the sheet (header is row 1).');
        self::assertStringContainsString('duplicate SKU, already on row 2', $errors[2]['message']);
        self::assertNull($this->findProduct('IMP-BAD-1'));
    }

    public function testTwoProductsWithTheSameNameGetDistinctUrls(): void
    {
        $this->import([
            ['IMP-SG-1', 'Welding gloves', 'Safety & PPE', '5', '', '', '', ''],
            ['IMP-SG-2', 'Welding gloves', 'Safety & PPE', '6', '', '', '', ''],
        ]);

        self::assertSame('welding-gloves', $this->product('IMP-SG-1')->getSlug());
        self::assertSame('welding-gloves-imp-sg-2', $this->product('IMP-SG-2')->getSlug());
    }

    public function testTrademarkSignsStayOutOfTheProductUrl(): void
    {
        $this->import([['IMP-TM-1', 'Allegro® Respirator™ Cleaning Pads', 'Safety & PPE', '17.40', '', '', '', '']]);

        self::assertSame('allegro-respirator-cleaning-pads', $this->product('IMP-TM-1')->getSlug());
    }

    public function testAnImageThatCannotBeFetchedIsAWarningNotAFailure(): void
    {
        $path = $this->dir.'/with-image.xlsx';
        $writer = new Writer();
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['sku', 'name', 'category', 'price', 'image_url']));
        // A private address: refused before any request is made.
        $writer->addRow(Row::fromValues(['IMP-IMG-1', 'Pictured part', 'MRO Materials', '5', 'http://127.0.0.1/part.png']));
        $writer->close();

        $report = $this->importer()->import(new ExcelProductSource($path));
        $this->entityManager()->clear();

        self::assertSame(1, $report->created);
        self::assertFalse($report->hasErrors());
        self::assertStringContainsString('image not added', $report->messages(ImportReport::WARNING)[0]['message'] ?? '');
        self::assertCount(0, $this->product('IMP-IMG-1')->getImages());
    }

    public function testADryRunReportsButSavesNothing(): void
    {
        $report = $this->import([['IMP-DRY-1', 'Dry', 'MRO Materials', '1', '', 'Acme', '', 'yes']], dryRun: true);

        self::assertTrue($report->dryRun);
        self::assertSame(1, $report->created);
        self::assertSame(1, $report->createdLive);
        self::assertNull($this->findProduct('IMP-DRY-1'));
        self::assertNull($this->entityManager()->getRepository(ProductImportState::class)->findOneBy(['productCode' => 'IMP-DRY-1']));
    }

    public function testTheCommandPrintsASummaryAndFailsWhenRowsAreSkipped(): void
    {
        $path = $this->sheet([
            ['IMP-CMD-1', 'Command one', 'MRO Materials', '1', '', '', '', ''],
            ['IMP-CMD-2', 'No price', 'MRO Materials', '', '', '', '', ''],
        ]);
        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('app:catalog:import'));

        $exitCode = $tester->execute(['file' => $path]);

        self::assertSame(1, $exitCode);
        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/Created \(hidden drafts\)\s+1/', $display);
        self::assertStringContainsString('price is required', $display);
        self::assertNotNull($this->findProduct('IMP-CMD-1'));
    }

    public function testTheCommandRejectsAFileThatIsNotASpreadsheet(): void
    {
        $path = $this->dir.'/not-really.xlsx';
        file_put_contents($path, 'hello');
        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('app:catalog:import'));

        self::assertSame(1, $tester->execute(['file' => $path]));
        self::assertStringContainsString('could not be read as a spreadsheet', $tester->getDisplay());
    }

    public function testTheTemplateImportsCleanly(): void
    {
        $path = $this->dir.'/template.xlsx';
        $template = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('app:catalog:import-template'));
        $template->execute(['path' => $path]);

        $report = $this->importer()->import(new ExcelProductSource($path), dryRun: true);

        self::assertSame(1, $report->created);
        self::assertFalse($report->hasErrors());
    }

    /** @param list<list<string>> $rows */
    private function import(array $rows, bool $dryRun = false): ImportReport
    {
        $report = $this->importer()->import(new ExcelProductSource($this->sheet($rows)), $dryRun);
        $this->entityManager()->clear();

        return $report;
    }

    private function importer(): ProductImporter
    {
        $importer = static::getContainer()->get(ProductImporter::class);
        self::assertInstanceOf(ProductImporter::class, $importer);

        return $importer;
    }

    /** @param list<list<string>> $rows */
    private function sheet(array $rows): string
    {
        $path = $this->dir.'/products-'.bin2hex(random_bytes(3)).'.xlsx';
        $writer = new Writer();
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(self::HEADER));
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return $path;
    }

    private function findProduct(string $code): ?ProductInterface
    {
        $product = $this->entityManager()->getRepository(Product::class)->findOneBy(['code' => $code]);
        if ($product instanceof ProductInterface) {
            $product->setCurrentLocale('en_US');
            $product->setFallbackLocale('en_US');
        }

        return $product instanceof ProductInterface ? $product : null;
    }

    private function product(string $code): ProductInterface
    {
        $product = $this->findProduct($code);
        self::assertNotNull($product, sprintf('Product %s was not imported.', $code));

        return $product;
    }

    private function variant(ProductInterface $product): ProductVariantInterface
    {
        $variant = $product->getVariants()->first();
        self::assertInstanceOf(ProductVariantInterface::class, $variant);

        return $variant;
    }

    private function pricing(ProductInterface $product): ChannelPricingInterface
    {
        $pricing = $this->variant($product)->getChannelPricings()->first();
        self::assertInstanceOf(ChannelPricingInterface::class, $pricing);

        return $pricing;
    }

    private function price(ProductInterface $product): ?int
    {
        return $this->pricing($product)->getPrice();
    }

    private function removeImportedProducts(): void
    {
        $entityManager = $this->entityManager();
        foreach ($entityManager->getRepository(Product::class)->findAll() as $product) {
            if ($product instanceof ProductInterface && str_starts_with((string) $product->getCode(), 'IMP-')) {
                $entityManager->remove($product);
            }
        }
        $entityManager->createQuery(sprintf("DELETE FROM %s s WHERE s.productCode LIKE 'IMP-%%'", ProductImportState::class))->execute();
        $entityManager->flush();
        $entityManager->clear();
    }
}
