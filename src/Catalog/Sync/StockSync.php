<?php

declare(strict_types=1);

namespace App\Catalog\Sync;

use App\Catalog\Import\ImportReport;
use App\Entity\Catalog\ProductImportState;
use App\Entity\Product\ProductVariant;
use App\Supplier\OrsNasco\BulkAvailabilityClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;

/**
 * Copies ORS Nasco stock onto imported products: on hand = ORS's total
 * available across all warehouses, and the variant is tracked.
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
        private readonly ImportedSkus $importedSkus,
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
            foreach (array_chunk($this->importedSkus->all($only), self::BATCH_SIZE) as $skus) {
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
     * @param list<string>                                              $skus
     * @param array<string, \App\Supplier\OrsNasco\AvailabilityRow> $available
     */
    private function syncBatch(array $skus, array $available, ImportReport $report): void
    {
        $variants = [];
        foreach ($this->entityManager->getRepository(ProductVariant::class)->findBy(['code' => $skus]) as $variant) {
            if ($variant instanceof ProductVariantInterface) {
                $variants[(string) $variant->getCode()] = $variant;
            }
        }
        $states = [];
        foreach ($this->entityManager->getRepository(ProductImportState::class)->findBy(['productCode' => $skus]) as $state) {
            $states[$state->getProductCode()] = $state;
        }

        foreach ($skus as $sku) {
            $variant = $variants[$sku] ?? null;
            if (!$variant instanceof ProductVariantInterface) {
                ++$report->untouched;
                $report->add(0, $sku, ImportReport::WARNING, 'no variant with this SKU; left as it is');
                continue;
            }

            $row = $available[$sku] ?? null;
            if (null === $row) {
                ++$report->untouched;
                $report->add(0, $sku, ImportReport::WARNING, 'not in the ORS availability list; stock left as it is');
                continue;
            }

            if ($variant->isTracked() && $variant->getOnHand() === $row->totalAvailable) {
                ++$report->unchanged;
            } else {
                $report->add(0, $sku, ImportReport::INFO, sprintf(
                    'stock %s -> %d %s',
                    $variant->isTracked() ? (string) $variant->getOnHand() : 'not tracked',
                    $row->totalAvailable,
                    $row->unitOfMeasure,
                ));
                $variant->setTracked(true);
                $variant->setOnHand($row->totalAvailable);
                ++$report->updated;
            }

            $state = $states[$sku] ?? null;
            if (null !== $state && ($state->getImportedValues()['stock'] ?? null) !== $row->totalAvailable) {
                $state->recordImport($state->getSource(), ['stock' => $row->totalAvailable] + $state->getImportedValues());
            }
        }
    }
}
