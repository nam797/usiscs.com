<?php

declare(strict_types=1);

namespace App\Distributor;

use App\Entity\Catalog\ProductImportState;
use App\Entity\Catalog\SupplierItem;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Supplier SKU from the import state (ORS "012-92-500-7.5-8" for product
 * code "012-92-500-7_5-8"), cost from the ORS price sync.
 */
final class SupplierSkuLookup implements SupplierSkuLookupInterface
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function find(array $variantCodes): array
    {
        if ([] === $variantCodes) {
            return [];
        }

        $found = [];
        foreach ($this->entityManager->getRepository(ProductImportState::class)->findBy(['productCode' => $variantCodes]) as $state) {
            $found[$state->getProductCode()] = ['supplierSku' => $state->getSupplierSku(), 'unitCostInCents' => null];
        }
        foreach ($this->entityManager->getRepository(SupplierItem::class)->findBy(['sku' => $variantCodes]) as $item) {
            $found[$item->getSku()] = [
                'supplierSku' => $found[$item->getSku()]['supplierSku'] ?? $item->getSku(),
                'unitCostInCents' => $item->getCostInCents(),
            ];
        }

        return $found;
    }
}
