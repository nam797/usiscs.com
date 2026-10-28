<?php

declare(strict_types=1);

namespace App\Catalog\Sync;

use App\Entity\Catalog\SupplierItem;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The SKUs a distributor's syncs look after: those with a source for that
 * distributor (SupplierItem). Another distributor's sources for the same
 * SKU are never touched, and products made by hand without a source are
 * left alone.
 */
final class DistributorSkus
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param list<string>|null $only limit to these SKUs, as product codes or as the distributor writes them
     *
     * @return array<string, string> product code => the distributor's SKU (often the same)
     */
    public function all(string $distributor, ?array $only = null): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('s.sku', 's.supplierSku')
            ->from(SupplierItem::class, 's')
            ->where('s.supplier = :distributor')
            ->setParameter('distributor', $distributor)
            ->orderBy('s.sku');

        if (null !== $only) {
            $query->andWhere('s.sku IN (:only) OR s.supplierSku IN (:only)')->setParameter('only', $only);
        }

        $skus = [];
        foreach ($query->getQuery()->getArrayResult() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = $row['sku'] ?? null;
            $supplierSku = $row['supplierSku'] ?? null;
            if (is_string($code)) {
                $skus[$code] = is_string($supplierSku) ? $supplierSku : $code;
            }
        }

        return $skus;
    }
}
