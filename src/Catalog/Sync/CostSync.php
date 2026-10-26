<?php

declare(strict_types=1);

namespace App\Catalog\Sync;

use App\Catalog\GoLive\ReadinessChecker;
use App\Catalog\Import\ImportReport;
use App\Entity\Catalog\SupplierItem;
use App\Entity\Product\ProductVariant;
use App\Supplier\OrsNasco\PriceClientInterface;
use App\Supplier\OrsNasco\PriceRow;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;

/**
 * Records what ORS Nasco charges us for each imported SKU (SupplierItem).
 *
 * It never changes the selling price -- that stays the channel pricing from
 * the import or the admin -- but it reports every product that would sell
 * at or below cost, and the ReadinessChecker then keeps such products from
 * going live. ORS problems ("Product is Inactive", "Invalid Product
 * Number", ...) are reported; products are never taken offline here.
 */
final class CostSync
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PriceClientInterface $client,
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
        $skus = $this->importedSkus->all($only);
        // Every request is made first: one failed call means nothing is written.
        $prices = [] === $skus ? [] : $this->client->fetch($skus);

        $report = new ImportReport($dryRun);
        $connection = $this->entityManager->getConnection();
        if ($dryRun) {
            $connection->beginTransaction();
        }

        try {
            foreach (array_chunk($skus, self::BATCH_SIZE) as $batch) {
                $this->syncBatch($batch, $prices, $report);
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
     * @param list<string>            $skus
     * @param array<string, PriceRow> $prices
     */
    private function syncBatch(array $skus, array $prices, ImportReport $report): void
    {
        $items = [];
        foreach ($this->entityManager->getRepository(SupplierItem::class)->findBy(['sku' => $skus]) as $item) {
            $items[$item->getSku()] = $item;
        }
        $variants = [];
        foreach ($this->entityManager->getRepository(ProductVariant::class)->findBy(['code' => $skus]) as $variant) {
            if ($variant instanceof ProductVariantInterface) {
                $variants[(string) $variant->getCode()] = $variant;
            }
        }

        foreach ($skus as $sku) {
            $row = $prices[$sku] ?? null;
            if (null === $row) {
                ++$report->untouched;
                $report->add(0, $sku, ImportReport::WARNING, 'ORS did not answer for this SKU; cost left as it is');
                continue;
            }

            $item = $items[$sku] ?? null;
            if (null === $item) {
                $item = new SupplierItem($sku, SupplierItem::ORS_NASCO);
                $this->entityManager->persist($item);
            }

            $problem = $row->problem();
            $before = $item->getCostInCents();
            $changed = $item->record($row->costInCents, $row->unitSell, $row->unitConversion, $row->error);

            if (null !== $problem) {
                ++$report->untouched;
                $report->add(0, $sku, ImportReport::WARNING, sprintf('ORS: %s', $problem));
            } elseif ($changed) {
                ++$report->updated;
                $report->add(0, $sku, ImportReport::INFO, sprintf('cost %s -> %s per %s', self::money($before), self::money($row->costInCents), $row->unitSell ?? 'unit'));
            } else {
                ++$report->unchanged;
            }

            $variant = $variants[$sku] ?? null;
            $product = $variant?->getProduct();
            if (null !== $row->costInCents && null !== $variant && $product instanceof ProductInterface) {
                foreach (ReadinessChecker::belowCost($product, $variant, $row->costInCents) as $belowCost) {
                    $report->add(0, $sku, ImportReport::WARNING, $belowCost.($product->isEnabled() ? ' (product is live)' : ''));
                }
            }
        }
    }

    private static function money(?int $cents): string
    {
        return null === $cents ? 'none' : '$'.number_format($cents / 100, 2);
    }
}
