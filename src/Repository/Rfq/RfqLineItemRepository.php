<?php

declare(strict_types=1);

namespace App\Repository\Rfq;

use App\Entity\Rfq\RfqLineItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RfqLineItem>
 */
class RfqLineItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RfqLineItem::class);
    }
}
