<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Catalog\Import\ExcelProductSource;
use App\Catalog\Import\ImportReport;
use App\Catalog\Import\ProductImporter;
use App\Entity\Catalog\ProductImportState;
use App\Entity\Order\OrderItem;
use App\Entity\Product\Product;
use App\Entity\Product\ProductVariant;
use App\Tests\Functional\FunctionalTestCase;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Twig\Environment;

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

    public function testAPackUnitIsShownWithThePrice(): void
    {
        $this->importWithUnit([['IMP-PK-1', 'Nitrile gloves', 'Safety & PPE', '305.74', '', '', '', 'yes', '144 PR = 1 CA']]);

        $variant = $this->variant($this->product('IMP-PK-1'));
        self::assertInstanceOf(ProductVariant::class, $variant);
        self::assertSame('144 PR = 1 CA', $variant->getPackUnit()?->toString());

        $this->client->request('GET', '/products/nitrile-gloves');
        self::assertSelectorTextContains('[data-test-pack-unit]', 'per case of 144 pairs');
        $this->client->request('GET', '/safety-ppe');
        self::assertSelectorTextContains('body', 'per case of 144 pairs');

        // The cart and order line ("Sold per ..."), rendered on its own: a real cart needs the add-to-cart live component.
        $item = new OrderItem();
        $item->setVariant($this->variant($this->product('IMP-PK-1')));
        $twig = static::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $html = $twig->createTemplate("{{ component('sylius_shop:order_item:product_info', {item: item}) }}")->render(['item' => $item]);
        self::assertStringContainsString('Sold per case of 144 pairs', $html);
    }

    public function testASupplierSkuWithADotGetsAValidCodeAndKeepsItsOwnSku(): void
    {
        $report = $this->import([['IMP-92-500-7.5-8', 'Cut gloves size 7.5', 'Safety & PPE', '9.50', '', '', '', '']]);

        self::assertSame(1, $report->created);
        self::assertSame('IMP-92-500-7_5-8', $this->variant($this->product('IMP-92-500-7_5-8'))->getCode());
        $state = $this->entityManager()->getRepository(ProductImportState::class)->findOneBy(['productCode' => 'IMP-92-500-7_5-8']);
        self::assertInstanceOf(ProductImportState::class, $state);
        self::assertSame('IMP-92-500-7.5-8', $state->getSupplierSku());
    }

    public function testSkusInAGroupBecomeVariantsOfOneProduct(): void
    {
        $report = $this->importGrouped([
            ['IMP-HF-10', 'HyFlex 11-600 Gloves', 'Safety & PPE', '622.22', 'yes', 'IMP-HYFLEX', 'Size 10', '144 PR = 1 CA'],
            ['IMP-HF-8', 'HyFlex 11-600 Gloves', 'Safety & PPE', '51.85', 'yes', 'IMP-HYFLEX', 'Size 8', '12 PR = 1 DZ'],
            ['IMP-HF-9', 'HyFlex 11-600 Gloves', 'Safety & PPE', '51.85', 'yes', 'IMP-HYFLEX', 'Size 9', '12 PR = 1 DZ'],
        ]);

        self::assertSame(1, $report->created, implode("\n", array_column($report->messages(), 'message')));
        self::assertSame(2, $report->added, 'The other two SKUs are choices of the new product.');
        $product = $this->product('IMP-HYFLEX');
        self::assertTrue($product->isEnabled());
        self::assertSame('HyFlex 11-600 Gloves', $product->getName());
        self::assertSame(ProductInterface::VARIANT_SELECTION_CHOICE, $product->getVariantSelectionMethod());
        $names = [];
        foreach ($product->getVariants() as $variant) {
            $names[(string) $variant->getCode()] = $variant->getTranslation('en_US')->getName();
        }
        self::assertSame(['IMP-HF-8' => 'Size 8', 'IMP-HF-9' => 'Size 9', 'IMP-HF-10' => 'Size 10'], $names, 'Listed in natural order.');
        self::assertNull($this->findProduct('IMP-HF-8'), 'No product of its own per SKU.');

        $this->client->request('GET', '/products/hyflex-11-600-gloves');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Size 10');
        self::assertSelectorTextContains('body', 'per case of 144 pairs');
    }

    public function testChoicesAreListedInNaturalOrderWhateverTheSheetOrder(): void
    {
        $rows = [];
        foreach (['White, Size 6', 'Black, Size 10', 'Black, Size 6', 'Black, Size 11', 'White, Size 10'] as $i => $name) {
            $rows[] = ['IMP-NAT-'.$i, 'Natural gloves', 'Safety & PPE', '10', '', 'IMP-NATURAL', $name, ''];
        }
        $this->importGrouped($rows);

        $names = [];
        foreach ($this->product('IMP-NATURAL')->getVariants() as $variant) {
            $names[] = $variant->getTranslation('en_US')->getName();
        }
        self::assertSame(['Black, Size 6', 'Black, Size 10', 'Black, Size 11', 'White, Size 6', 'White, Size 10'], $names);
    }

    public function testReimportingAGroupUpdatesItsSkusAndAddsNewOnes(): void
    {
        $rows = [
            ['IMP-HF-8', 'HyFlex 11-600 Gloves', 'Safety & PPE', '51.85', '', 'IMP-HYFLEX', 'Size 8', ''],
            ['IMP-HF-9', 'HyFlex 11-600 Gloves', 'Safety & PPE', '51.85', '', 'IMP-HYFLEX', 'Size 9', ''],
        ];
        $this->importGrouped($rows);
        $product = $this->product('IMP-HYFLEX');
        $product->getTranslation('en_US')->setName('HyFlex® 11-600 Palm-Coated Gloves');
        $this->entityManager()->flush();
        $this->entityManager()->clear();

        $rows[1][3] = '49.99';
        $rows[] = ['IMP-HF-10', 'HyFlex 11-600 Gloves', 'Safety & PPE', '51.85', '', 'IMP-HYFLEX', 'Size 10', ''];
        $report = $this->importGrouped($rows);

        self::assertSame(1, $report->added);
        self::assertSame(1, $report->updated);
        self::assertSame(1, $report->unchanged);
        $product = $this->product('IMP-HYFLEX');
        self::assertSame('HyFlex® 11-600 Palm-Coated Gloves', $product->getName(), "The admin's name is kept.");
        self::assertCount(3, $product->getVariants());
        $variant = $this->entityManager()->getRepository(ProductVariant::class)->findOneBy(['code' => 'IMP-HF-9']);
        self::assertInstanceOf(ProductVariant::class, $variant);
        $pricing = $variant->getChannelPricings()->first();
        self::assertInstanceOf(ChannelPricingInterface::class, $pricing);
        self::assertSame(4999, $pricing->getPrice());
    }

    public function testASkuInAGroupNeedsAVariantName(): void
    {
        $report = $this->importGrouped([['IMP-HF-8', 'HyFlex', 'Safety & PPE', '51.85', '', 'IMP-HYFLEX', '', '']]);

        self::assertSame(1, $report->failed);
        self::assertStringContainsString('variant_name is required', implode("\n", array_column($report->messages(), 'message')));
    }

    public function testASkuThatIsAlreadyItsOwnProductIsNotMovedIntoAGroup(): void
    {
        $this->import([['IMP-HF-8', 'HyFlex size 8', 'Safety & PPE', '51.85', '', '', '', '']]);

        $report = $this->importGrouped([['IMP-HF-8', 'HyFlex', 'Safety & PPE', '51.85', '', 'IMP-HYFLEX', 'Size 8', '']]);

        self::assertSame(1, $report->failed);
        self::assertStringContainsString('is already product "IMP-HF-8"', implode("\n", array_column($report->messages(), 'message')));
        self::assertNull($this->findProduct('IMP-HYFLEX'));
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
        self::assertMatchesRegularExpression('/New products, hidden drafts\s+1/', $display);
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

    /** @param list<list<string>> $rows rows with a unit_of_measure column at the end */
    private function importWithUnit(array $rows): ImportReport
    {
        $path = $this->sheet($rows, [...self::HEADER, 'unit_of_measure']);
        $report = $this->importer()->import(new ExcelProductSource($path));
        $this->entityManager()->clear();

        return $report;
    }

    /** @param list<list<string>> $rows sku, name, category, price, go_live, group, variant_name, unit_of_measure */
    private function importGrouped(array $rows): ImportReport
    {
        $path = $this->sheet($rows, ['sku', 'name', 'category', 'price', 'go_live', 'group', 'variant_name', 'unit_of_measure']);
        $report = $this->importer()->import(new ExcelProductSource($path));
        $this->entityManager()->clear();

        return $report;
    }

    private function importer(): ProductImporter
    {
        $importer = static::getContainer()->get(ProductImporter::class);
        self::assertInstanceOf(ProductImporter::class, $importer);

        return $importer;
    }

    /**
     * @param list<list<string>> $rows
     * @param list<string>       $header
     */
    private function sheet(array $rows, array $header = self::HEADER): string
    {
        $path = $this->dir.'/products-'.bin2hex(random_bytes(3)).'.xlsx';
        $writer = new Writer();
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($header));
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
