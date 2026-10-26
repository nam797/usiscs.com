<?php

declare(strict_types=1);

namespace App\Catalog\GoLive;

use App\Entity\Catalog\SupplierItem;
use Doctrine\ORM\EntityManagerInterface;

final class SupplierCostLookup implements SupplierCostLookupInterface
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function costInCents(string $sku): ?int
    {
        return $this->entityManager->getRepository(SupplierItem::class)->findOneBy(['sku' => $sku])?->getCostInCents();
    }
}
