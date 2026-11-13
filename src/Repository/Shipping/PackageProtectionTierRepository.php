<?php

declare(strict_types=1);

namespace App\Repository\Shipping;

use App\Entity\Shipping\PackageProtectionTier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PackageProtectionTier>
 */
class PackageProtectionTierRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PackageProtectionTier::class);
    }

    /** @return list<PackageProtectionTier> lowest "from" amount first */
    public function findAllInOrder(): array
    {
        return $this->findBy([], ['minItemsTotal' => 'ASC']);
    }
}
