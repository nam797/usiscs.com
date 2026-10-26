<?php

declare(strict_types=1);

namespace App\Catalog\Sync;

use App\Entity\Catalog\ProductImportState;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The SKUs the supplier syncs look after: products created by an import.
 * Products made by hand in the admin are never touched by a sync.
 */
final class ImportedSkus
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param list<string>|null $only limit to these SKUs, as product codes or as the supplier writes them
     *
     * @return array<string, string> product code => the supplier's SKU (often the same)
     */
    public function all(?array $only = null): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('s.productCode', 's.supplierSku')
            ->from(ProductImportState::class, 's')
            ->orderBy('s.productCode');

        if (null !== $only) {
            $query->where('s.productCode IN (:only) OR s.supplierSku IN (:only)')->setParameter('only', $only);
        }

        $skus = [];
        foreach ($query->getQuery()->getArrayResult() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = $row['productCode'] ?? null;
            $supplierSku = $row['supplierSku'] ?? null;
            if (is_string($code)) {
                $skus[$code] = is_string($supplierSku) ? $supplierSku : $code;
            }
        }

        return $skus;
    }
}
