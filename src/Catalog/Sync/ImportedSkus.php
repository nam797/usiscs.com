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
     * @param list<string>|null $only limit to these SKUs (still only imported ones)
     *
     * @return list<string>
     */
    public function all(?array $only = null): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('s.productCode')
            ->from(ProductImportState::class, 's')
            ->orderBy('s.productCode');

        if (null !== $only) {
            $query->where('s.productCode IN (:only)')->setParameter('only', $only);
        }

        /** @var list<string> $codes */
        $codes = $query->getQuery()->getSingleColumnResult();

        return $codes;
    }
}
