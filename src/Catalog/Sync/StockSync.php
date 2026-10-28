<?php

declare(strict_types=1);

namespace App\Catalog\Sync;

use App\Catalog\Import\ImportReport;
use App\Catalog\Stock\VariantStockTotal;
use App\Entity\Catalog\ProductImportState;
use App\Entity\Catalog\SupplierItem;
use App\Entity\Product\ProductVariant;
use App\Supplier\OrsNasco\BulkAvailabilityClientInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Records ORS Nasco's stock on each SKU's ORS source (its total available
 * across all warehouses), then sets the shop's on hand to the total over
 * the SKU's sources (VariantStockTotal), so another distributor's stock
 * counts too. The variant is tracked.
 *
 * Unlike the import's other fields, stock always follows ORS: orders change
 * on-hand counts in Sylius, so "differs from what the sync wrote" can't mean
 * "an admin edited it" here. The import snapshot is updated too, so a later
 * sheet import doesn't mistake the ORS count for an admin edit.
 *
 * SKUs missing from the ORS list are reported and left as they are, never
 * zeroed. Never changes whether a product is live.
 */
final class StockSync
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BulkAvailabilityClientInterface $client,
        private readonly DistributorSkus $distributorSkus,
    ) {
    }

    /**
     * @param list<string>|null $only limit to these SKUs
     *
     * @throws \App\Supplier\OrsNasco\OrsApiException before anything is written
     */
    public function sync(bool $dryRun = false, ?array $only = null): ImportReport
    {
        // Fetched first: if ORS fails, nothing has been touched.
        $available = $this->client->fetchAll();

        $report = new ImportReport($dryRun);
        $connection = $this->entityManager->getConnection();
        if ($dryRun) {
            $connection->beginTransaction();
        }

        try {
            foreach (array_chunk($this->distributorSkus->all(SupplierItem::ORS_NASCO, $only), self::BATCH_SIZE, true) as $skus) {
                $this->syncBatch($skus, $available, $report);
                $this->entityManager->flush();
                $this->entityManager->clear();
            }
        } finally {
            if ($dryRun && $connection->isTransactionActive()) {
                $connection->rollBack();
                $this->entityManager->clear();
            }
        }

        return $report;
    }

    /**
     * @param array<string, string>                                     $skus      product code => ORS SKU
     * @param array<string, \App\Supplier\OrsNasco\AvailabilityRow> $available
     */
    private function syncBatch(array $skus, array $available, ImportReport $report): void
    {
        $variants = [];
        foreach ($this->entityManager->getRepository(ProductVariant::class)->findBy(['code' => array_keys($skus)]) as $variant) {
            if ($variant instanceof ProductVariant) {
                $variants[(string) $variant->getCode()] = $variant;
            }
        }
        $states = [];
        foreach ($this->entityManager->getRepository(ProductImportState::class)->findBy(['productCode' => array_keys($skus)]) as $state) {
            $states[$state->getProductCode()] = $state;
        }

        foreach ($skus as $sku => $orsSku) {
            $sku = (string) $sku; // PHP turns an all-digit code key into an int
            $variant = $variants[$sku] ?? null;
            if (!$variant instanceof ProductVariant) {
                ++$report->untouched;
                $report->add(0, $sku, ImportReport::WARNING, 'no variant with this SKU; left as it is');
                continue;
            }

            $row = $available[$orsSku] ?? null;
            if (null === $row) {
                ++$report->untouched;
                $report->add(0, $sku, ImportReport::WARNING, 'not in the ORS availability list; stock left as it is');
                continue;
            }

            $variant->sourceFor(SupplierItem::ORS_NASCO)->recordStock($row->totalAvailable);
            $before = $variant->isTracked() ? $variant->getOnHand() : null;
            $total = VariantStockTotal::apply($variant) ?? $row->totalAvailable;

            if ($before === $total) {
                ++$report->unchanged;
            } else {
                $report->add(0, $sku, ImportReport::INFO, sprintf(
                    'stock %s -> %d %s%s',
                    null === $before ? 'not tracked' : (string) $before,
                    $total,
                    $row->unitOfMeasure,
                    $total === $row->totalAvailable ? '' : sprintf(' (ORS %d, other sources %d)', $row->totalAvailable, $total - $row->totalAvailable),
                ));
                ++$report->updated;
            }

            $state = $states[$sku] ?? null;
            if (null !== $state && ($state->getImportedValues()['stock'] ?? null) !== $total) {
                $state->recordImport($state->getSource(), ['stock' => $total] + $state->getImportedValues());
            }
        }
    }
}
