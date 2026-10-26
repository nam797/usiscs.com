<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Catalog\GoLive\ReadinessChecker;
use App\Catalog\Import\ExcelProductSource;
use App\Catalog\Import\ProductImporter;
use App\Entity\Catalog\ProductImportState;
use App\Entity\Catalog\SupplierItem;
use App\Entity\Product\Product;
use App\Entity\Product\ProductVariant;
use App\Tests\Fake\FakeOrsNasco;
use App\Tests\Functional\FunctionalTestCase;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The ORS stock and cost syncs against the test database, with ORS itself
 * replaced by FakeOrsNasco. Every SKU here starts with "ORS-T" and is
 * removed before each test; commands run with --sku so products left by
 * other tests are never touched.
 */
final class OrsSyncTest extends FunctionalTestCase
{
    private const SKUS = ['ORS-T1', 'ORS-T2', 'ORS-T3', 'ORS-T4_5'];

    private FakeOrsNasco $ors;

    protected function setUp(): void
    {
        parent::setUp();
        $ors = static::getContainer()->get(FakeOrsNasco::class);
        self::assertInstanceOf(FakeOrsNasco::class, $ors);
        $this->ors = $ors;
        $this->ors->reset();
        $this->removeTestProducts();
        $this->importProducts();
    }

    public function testStockFollowsOrsTotalAvailable(): void
    {
        $this->ors->willHaveStock('ORS-T1', 340);
        $this->ors->willHaveStock('ORS-T2', 0, 'PK');

        $tester = $this->runCommand('app:ors:sync-stock');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(340, $this->variant('ORS-T1')->getOnHand());
        self::assertTrue($this->variant('ORS-T2')->isTracked());
        self::assertSame(0, $this->variant('ORS-T2')->getOnHand());
    }

    public function testOrsIsAskedForItsOwnSkuWhenTheProductCodeDiffers(): void
    {
        $this->ors->willHaveStock('ORS-T4.5', 60);
        $this->ors->willCost('ORS-T4.5', 120);

        $this->runCommand('app:ors:sync-stock');
        $this->runCommand('app:ors:sync-cost');

        self::assertSame(60, $this->variant('ORS-T4_5')->getOnHand());
        self::assertSame(120, $this->supplierItem('ORS-T4_5')?->getCostInCents());
    }

    public function testASkuCanBePickedByEitherSpelling(): void
    {
        $this->ors->willHaveStock('ORS-T4.5', 60);

        $tester = $this->runCommand('app:ors:sync-stock', ['--sku' => ['ORS-T4.5']]);

        self::assertSame(60, $this->variant('ORS-T4_5')->getOnHand());
        self::assertStringNotContainsString('ORS-T1', $tester->getDisplay());
    }

    public function testAnItemMissingFromTheOrsListKeepsItsStock(): void
    {
        $this->ors->willHaveStock('ORS-T1', 340);

        $tester = $this->runCommand('app:ors:sync-stock');

        self::assertSame(25, $this->variant('ORS-T3')->getOnHand(), 'Imported with 25; never zeroed.');
        self::assertStringContainsString('not in the ORS availability list', $tester->getDisplay());
    }

    public function testTheSyncedStockIsNotMistakenForAnAdminEditByTheNextImport(): void
    {
        $this->ors->willHaveStock('ORS-T1', 340);
        $this->runCommand('app:ors:sync-stock');

        $state = $this->entityManager()->getRepository(ProductImportState::class)->findOneBy(['productCode' => 'ORS-T1']);
        self::assertInstanceOf(ProductImportState::class, $state);
        self::assertSame(340, $state->getImportedValues()['stock'] ?? null);
    }

    public function testADryRunChangesNothing(): void
    {
        $this->ors->willHaveStock('ORS-T1', 340);
        $this->ors->willCost('ORS-T1', 500);

        $stock = $this->runCommand('app:ors:sync-stock', ['--dry-run' => true]);
        $cost = $this->runCommand('app:ors:sync-cost', ['--dry-run' => true]);

        self::assertStringContainsString('Dry run: nothing was saved', $stock->getDisplay());
        self::assertStringContainsString('Dry run: nothing was saved', $cost->getDisplay());
        self::assertSame(25, $this->variant('ORS-T1')->getOnHand());
        self::assertNull($this->supplierItem('ORS-T1'));
    }

    public function testAFailedOrsCallChangesNothingAndFailsTheCommand(): void
    {
        $this->ors->willFail('ORS availability API answered HTTP 503');

        $tester = $this->runCommand('app:ors:sync-stock');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Nothing was changed', $tester->getDisplay());
        self::assertSame(25, $this->variant('ORS-T1')->getOnHand());
    }

    public function testCostIsRecordedPerSkuWithOrsProblemsReported(): void
    {
        $this->ors->willCost('ORS-T1', 1243, unitSell: 'PK');
        $this->ors->willCost('ORS-T2', null, 'Product is Inactive');

        $tester = $this->runCommand('app:ors:sync-cost');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $item = $this->supplierItem('ORS-T1');
        self::assertNotNull($item);
        self::assertSame(1243, $item->getCostInCents());
        self::assertSame('PK', $item->getUnitSell());
        self::assertStringContainsString('ORS: Product is Inactive', $tester->getDisplay());
        self::assertStringContainsString('ORS did not answer for this SKU', $tester->getDisplay());
        self::assertTrue($this->product('ORS-T2')->isEnabled(), 'The sync never takes products offline.');
    }

    public function testAPriceAtOrBelowCostIsFlaggedAndBlocksGoingLive(): void
    {
        // ORS-T3 sells for $9.50.
        $this->ors->willCost('ORS-T3', 1000);

        $tester = $this->runCommand('app:ors:sync-cost');

        self::assertStringContainsString('is not above the supplier cost $10.00 (product is live)', $tester->getDisplay());

        $checker = static::getContainer()->get(ReadinessChecker::class);
        self::assertInstanceOf(ReadinessChecker::class, $checker);
        self::assertSame(
            ['price $9.50 in channel "default" is not above the supplier cost $10.00'],
            $checker->problems($this->product('ORS-T3')),
        );
    }

    /** @param array<string, mixed> $options */
    private function runCommand(string $command, array $options = []): CommandTester
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find($command));
        $tester->execute($options + ['--sku' => self::SKUS]);
        $this->entityManager()->clear();

        return $tester;
    }

    private function importProducts(): void
    {
        $path = sys_get_temp_dir().'/usiscs-ors-e2e-'.bin2hex(random_bytes(4)).'.xlsx';
        $writer = new Writer();
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['sku', 'name', 'category', 'price', 'stock_qty', 'go_live']));
        $writer->addRow(Row::fromValues(['ORS-T1', 'ORS test blades', 'Safety & PPE', '17.40', '25', 'yes']));
        $writer->addRow(Row::fromValues(['ORS-T2', 'ORS test nut', 'Safety & PPE', '1.20', '25', 'yes']));
        $writer->addRow(Row::fromValues(['ORS-T3', 'ORS test gloves', 'Safety & PPE', '9.50', '25', 'yes']));
        $writer->addRow(Row::fromValues(['ORS-T4.5', 'ORS test dotted', 'Safety & PPE', '3.00', '25', 'yes']));
        $writer->close();

        try {
            $importer = static::getContainer()->get(ProductImporter::class);
            self::assertInstanceOf(ProductImporter::class, $importer);
            $report = $importer->import(new ExcelProductSource($path), false);
            self::assertSame(4, $report->createdLive, 'Run app:catalog:setup-categories first.');
        } finally {
            unlink($path);
        }
        $this->entityManager()->clear();
    }

    private function removeTestProducts(): void
    {
        $entityManager = $this->entityManager();
        foreach ($entityManager->getRepository(Product::class)->findBy(['code' => self::SKUS]) as $product) {
            $entityManager->remove($product);
        }
        $entityManager->createQuery(sprintf('DELETE FROM %s s WHERE s.productCode IN (:skus)', ProductImportState::class))->execute(['skus' => self::SKUS]);
        $entityManager->createQuery(sprintf('DELETE FROM %s s WHERE s.sku IN (:skus)', SupplierItem::class))->execute(['skus' => self::SKUS]);
        $entityManager->flush();
        $entityManager->clear();
    }

    private function product(string $code): ProductInterface
    {
        $product = $this->entityManager()->getRepository(Product::class)->findOneBy(['code' => $code]);
        self::assertInstanceOf(ProductInterface::class, $product);

        return $product;
    }

    private function variant(string $code): ProductVariantInterface
    {
        $variant = $this->entityManager()->getRepository(ProductVariant::class)->findOneBy(['code' => $code]);
        self::assertInstanceOf(ProductVariantInterface::class, $variant);

        return $variant;
    }

    private function supplierItem(string $sku): ?SupplierItem
    {
        return $this->entityManager()->getRepository(SupplierItem::class)->findOneBy(['sku' => $sku]);
    }
}
